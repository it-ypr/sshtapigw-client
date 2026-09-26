<?php

namespace common\services\SshtApiGwClient\extensions\pacs;

use frontend\models\RdFoto;
use Yii;
use yii\db\Query;

/** @package common\services\SshtApiGwClient\extensions\pacs 
 * 
 * PacsMigrationService 
 * service untuk import data image legacy dari rd_foto ke orthanc pacs
 */
class PacsMigrationService
{
  private OrthancService $orthanc;

  private PacsLockService $lock;

  /**
   * db connection untuk akses rd_foto_orthanc.
   */
  private $dbLocal;

  public function __construct(
    ?OrthancService $orthanc = null,
    ?PacsLockService $lock = null
  ) {
    $this->orthanc = $orthanc ?? new OrthancService();

    $this->lock = $lock ?? new PacsLockService();

    $this->dbLocal = Yii::$app->db;
    // $this->dbLocal = Yii::$app->db3;
  }

  /**
   * Migrate seluruh foto radiologi dari rd_foto
   * berdasarkan tanggal pemeriksaan.
   */
  public function migrateByDate(
    string $tanggal
  ): array {
    $result = [
      'total_noradio' => 0,
      'total_foto' => 0,
      'success' => 0,
      'failed' => 0,
      'skipped' => 0,
    ];

    $noradios = (new Query())
      ->select([
        'rd_biodata.noradio',
      ])
      ->from('rd_biodata')
      ->where([
        'rd_biodata.tanggal' => $tanggal,
        'rd_biodata.ondelete' => 0,
      ])
      ->andWhere([
        'not',
        [
          'rd_biodata.noradio' => null,
        ],
      ])
      ->andWhere([
        '!=',
        'rd_biodata.noradio',
        '',
      ])
      ->distinct()
      ->column();

    $result['total_noradio'] =
      count($noradios);

    foreach ($noradios as $noradio) {
      $this->migrateNoradio(
        (string) $noradio,
        $result
      );
    }

    return $result;
  }

  /**
   * Acquire lock per noradio.
   *
   * Migration dan sharing tidak boleh
   * memproses noradio yang sama secara bersamaan.
   */
  private function migrateNoradio(
    string $noradio,
    array &$result
  ): void {
    $locked =
      $this->lock->acquire(
        $noradio,
        0
      );

    if (!$locked) {
      Yii::warning([
        'event' =>
        'pacs_migration_lock_failed',

        'noradio' =>
        $noradio,
      ], 'pacs-migration');

      return;
    }

    try {
      $this->migrateNoradioLocked(
        $noradio,
        $result
      );
    } finally {
      $this->lock->release(
        $noradio
      );
    }
  }

  /**
   * Migrate seluruh foto untuk satu noradio.
   *
   * Rule:
   *
   *   1 Study
   *   1 Series
   *   N Instance
   *
   * Identity image:
   *
   *   noradio + source_sequence
   *
   * source_sequence:
   *
   *   rd_foto.NoFoto
   */
  private function migrateNoradioLocked(
    string $noradio,
    array &$result
  ): void {
    $biodata = (new Query())
      ->select([
        'rd_biodata.*',

        'procedure_id' =>
        'rdp.Id',

        'procedure_kode' =>
        'rdp.kode',

        'procedure_nama' =>
        'rdp.nama',

        'procedure_loinc' =>
        'rdp.loinc',

        'procedure_modality' =>
        'rdp.modality',

        'doctor_name' =>
        'muser.nm_user',

        'worklist_accession' =>
        'rd_foto_hasil.accession',

        'worklist_status' =>
        'rd_foto_hasil.worklist_status',

        'worklist_created_at' =>
        'rd_foto_hasil.worklist_created_at',

        'worklist_updated_at' =>
        'rd_foto_hasil.worklist_updated_at',

        'worklist_finish_at' =>
        'rd_foto_hasil.worklist_finish_at',

        'worklist_foto' =>
        'rd_foto_hasil.foto',

        'worklist_id' =>
        'rd_foto_hasil.id',
      ])
      ->from('rd_biodata')
      ->leftJoin(
        'rd_order_poli',
        'rd_biodata.noradio = rd_order_poli.noradio'
      )
      ->leftJoin(
        'rd_daftar_periksa rdp',
        'rd_order_poli.kode = rdp.kode'
      )
      ->leftJoin(
        'muser',
        'muser.nik = rd_biodata.dperiksa'
      )
      ->leftJoin(
        'rd_foto_hasil',
        'rd_biodata.noradio = rd_foto_hasil.noradio'
      )
      ->where([
        'rd_biodata.noradio' => $noradio,
        'rd_biodata.ondelete' => 0,
        'rdp.kondisi' => 1,
      ])
      ->one();

    if (!$biodata) {
      Yii::warning([
        'event' =>
        'pacs_migration_biodata_not_found',

        'noradio' =>
        $noradio,
      ], 'pacs-migration');

      return;
    }

    $fotos = RdFoto::find()
      ->where([
        'noradio' => $noradio,
      ])
      ->orderBy([
        'NoFoto' => SORT_ASC,
      ])
      ->all();

    if (empty($fotos)) {
      Yii::warning([
        'event' =>
        'pacs_migration_foto_not_found',

        'noradio' =>
        $noradio,
      ], 'pacs-migration');

      return;
    }

    $result['total_foto'] +=
      count($fotos);

    /*
     * Cari mapping sukses yang sudah ada.
     *
     * Mapping ini dipakai untuk recovery
     * Study/Series hierarchy.
     */
    $existingMapping = (new Query())
      ->from('rd_foto_orthanc')
      ->where([
        'noradio' => $noradio,
        'status' => 1,
      ])
      ->andWhere([
        'not',
        [
          'orthanc_study_id' => null,
        ],
      ])
      ->orderBy([
        'id' => SORT_ASC,
      ])
      ->one($this->dbLocal);

    $orthancStudyId =
      $existingMapping['orthanc_study_id']
      ?? null;

    $orthancSeriesId =
      $existingMapping['orthanc_series_id']
      ?? null;

    /*
     * Kalau registry belum punya Study,
     * coba recovery langsung dari Orthanc.
     */
    if (empty($orthancStudyId)) {
      try {
        $orthancStudyId =
          $this->orthanc
          ->findStudyByAccessionNumber(
            $noradio
          );
      } catch (\Throwable $e) {
        Yii::warning([
          'event' =>
          'pacs_migration_find_study_failed',

          'noradio' =>
          $noradio,

          'message' =>
          $e->getMessage(),
        ], 'pacs-migration');
      }
    }

    /*
     * Kalau Study sudah ada tetapi Series belum
     * diketahui, ambil Series pertama.
     */
    if (
      !empty($orthancStudyId)
      && empty($orthancSeriesId)
    ) {
      try {
        $study =
          $this->orthanc->getStudy(
            $orthancStudyId
          );

        $orthancSeriesId =
          $study['Series'][0]
          ?? null;

        if (empty($orthancSeriesId)) {
          Yii::warning([
            'event' =>
            'pacs_migration_existing_study_without_series',

            'noradio' =>
            $noradio,

            'orthanc_study_id' =>
            $orthancStudyId,
          ], 'pacs-migration');
        }
      } catch (\Throwable $e) {
        Yii::warning([
          'event' =>
          'pacs_migration_get_existing_study_failed',

          'noradio' =>
          $noradio,

          'orthanc_study_id' =>
          $orthancStudyId,

          'message' =>
          $e->getMessage(),
        ], 'pacs-migration');
      }
    }

    foreach ($fotos as $foto) {
      $rdFotoId =
        $foto->getPrimaryKey();

      $sourceSequence =
        (int) $foto->NoFoto;

      /*
       * Identity utama image:
       *
       *   noradio + source_sequence
       *
       * rd_foto_id hanya reference source legacy.
       */
      $mapping = (new Query())
        ->from('rd_foto_orthanc')
        ->where([
          'noradio' =>
          $noradio,

          'source_sequence' =>
          $sourceSequence,
        ])
        ->one($this->dbLocal);

      /*
       * Kalau sudah sukses dan instance tersedia,
       * jangan create ulang.
       */
      if (
        $mapping
        && (int) $mapping['status'] === 1
        && !empty($mapping['orthanc_instance_id'])
      ) {
        $result['skipped']++;

        /*
         * Recovery hierarchy dari mapping.
         */
        if (
          empty($orthancStudyId)
          && !empty($mapping['orthanc_study_id'])
        ) {
          $orthancStudyId =
            $mapping['orthanc_study_id'];
        }

        if (
          empty($orthancSeriesId)
          && !empty($mapping['orthanc_series_id'])
        ) {
          $orthancSeriesId =
            $mapping['orthanc_series_id'];
        }

        Yii::info([
          'event' =>
          'pacs_migration_skipped',

          'noradio' =>
          $noradio,

          'rd_foto_id' =>
          $rdFotoId,

          'source_sequence' =>
          $sourceSequence,

          'orthanc_instance_id' =>
          $mapping['orthanc_instance_id'],

          'orthanc_study_id' =>
          $mapping['orthanc_study_id'],

          'orthanc_series_id' =>
          $mapping['orthanc_series_id'],
        ], 'pacs-migration');

        continue;
      }

      /*
       * Kalau mapping sebelumnya error,
       * proses ulang image tersebut.
       */

      $isFirstInstance =
        empty($orthancSeriesId);

      $tags =
        $this->buildDicomTags(
          $biodata,
          $noradio,
          $foto,
          $isFirstInstance
        );

      try {
        Yii::info([
          'event' =>
          'pacs_migration_create_dicom',

          'noradio' =>
          $noradio,

          'rd_foto_id' =>
          $rdFotoId,

          'NoFoto' =>
          $foto->NoFoto,

          'source_sequence' =>
          $sourceSequence,

          'is_first_instance' =>
          $isFirstInstance,

          'parent_study_id' =>
          $orthancStudyId,

          'parent_series_id' =>
          $orthancSeriesId,

          'tags' =>
          $tags,
        ], 'pacs-migration');

        /*
         * CREATE DICOM
         *
         * Instance pertama:
         *   Parent = null
         *
         * Instance berikutnya:
         *   Parent = Series ID
         */
        $response =
          $this->orthanc->createDicom(
            $foto->foto,
            $tags,
            $orthancSeriesId
          );

        $orthancInstanceId =
          $response['ID'] ?? null;

        if (
          empty($orthancInstanceId)
        ) {
          throw new \RuntimeException(
            'Orthanc create-dicom tidak mengembalikan ID instance.'
          );
        }

        /*
         * GET INSTANCE
         */
        $instance =
          $this->orthanc->getInstance(
            $orthancInstanceId
          );

        $orthancPatientId =
          $instance['ParentPatient']
          ?? $response['ParentPatient']
          ?? null;

        $currentSeriesId =
          $instance['ParentSeries']
          ?? $response['ParentSeries']
          ?? null;

        if (
          empty($currentSeriesId)
        ) {
          throw new \RuntimeException(
            'Orthanc instance tidak memiliki ParentSeries.'
          );
        }

        /*
         * Pastikan Instance masuk
         * ke Series yang benar.
         */
        if (
          !empty($orthancSeriesId)
          && $currentSeriesId !==
          $orthancSeriesId
        ) {
          throw new \RuntimeException(
            sprintf(
              'Instance masuk ke Series berbeda. Expected=%s Actual=%s',
              $orthancSeriesId,
              $currentSeriesId
            )
          );
        }

        $orthancSeriesId =
          $currentSeriesId;

        /*
         * GET SERIES
         */
        $series =
          $this->orthanc->getSeries(
            $orthancSeriesId
          );

        $currentStudyId =
          $series['ParentStudy']
          ?? $response['ParentStudy']
          ?? $orthancStudyId
          ?? null;

        if (
          empty($currentStudyId)
        ) {
          throw new \RuntimeException(
            'Orthanc series tidak memiliki ParentStudy.'
          );
        }

        /*
         * Pastikan Study tetap sama.
         */
        if (
          !empty($orthancStudyId)
          && $currentStudyId !==
          $orthancStudyId
        ) {
          throw new \RuntimeException(
            sprintf(
              'Instance masuk ke Study berbeda. Expected=%s Actual=%s',
              $orthancStudyId,
              $currentStudyId
            )
          );
        }

        $orthancStudyId =
          $currentStudyId;

        /*
         * GET STUDY
         */
        $study =
          $this->orthanc->getStudy(
            $orthancStudyId
          );

        /*
         * Ambil UID yang dibuat Orthanc.
         */
        $sopInstanceUid =
          $instance['MainDicomTags']['SOPInstanceUID']
          ?? null;

        $seriesInstanceUid =
          $series['MainDicomTags']['SeriesInstanceUID']
          ?? null;

        $studyInstanceUid =
          $study['MainDicomTags']['StudyInstanceUID']
          ?? null;

        if (
          empty($studyInstanceUid)
          || empty($seriesInstanceUid)
          || empty($sopInstanceUid)
        ) {
          Yii::error([
            'event' =>
            'pacs_migration_uid_incomplete',

            'noradio' =>
            $noradio,

            'rd_foto_id' =>
            $rdFotoId,

            'source_sequence' =>
            $sourceSequence,

            'orthanc_instance_id' =>
            $orthancInstanceId,

            'orthanc_series_id' =>
            $orthancSeriesId,

            'orthanc_study_id' =>
            $orthancStudyId,
          ], 'pacs-migration');

          throw new \RuntimeException(
            'UID DICOM tidak lengkap pada hierarchy Orthanc.'
          );
        }

        /*
         * SAVE MAPPING.
         *
         * Identity:
         *
         *   noradio + source_sequence
         *
         * rd_foto_id tetap disimpan sebagai
         * reference source legacy.
         */
        $this->saveSuccessMapping(
          $foto,
          $noradio,
          $sourceSequence,
          $orthancPatientId,
          $orthancStudyId,
          $orthancSeriesId,
          $orthancInstanceId,
          $studyInstanceUid,
          $seriesInstanceUid,
          $sopInstanceUid
        );

        $result['success']++;

        Yii::info([
          'event' =>
          'pacs_migration_success',

          'noradio' =>
          $noradio,

          'rd_foto_id' =>
          $rdFotoId,

          'NoFoto' =>
          $foto->NoFoto,

          'source_sequence' =>
          $sourceSequence,

          'orthanc_patient_id' =>
          $orthancPatientId,

          'orthanc_study_id' =>
          $orthancStudyId,

          'orthanc_series_id' =>
          $orthancSeriesId,

          'orthanc_instance_id' =>
          $orthancInstanceId,

          'study_instance_uid' =>
          $studyInstanceUid,

          'series_instance_uid' =>
          $seriesInstanceUid,

          'sop_instance_uid' =>
          $sopInstanceUid,
        ], 'pacs-migration');
      } catch (\Throwable $e) {
        $result['failed']++;

        try {
          $this->saveErrorMapping(
            $foto,
            $noradio,
            $sourceSequence,
            $e->getMessage()
          );
        } catch (\Throwable $mappingError) {
          Yii::error([
            'event' =>
            'pacs_migration_error_mapping_failed',

            'noradio' =>
            $noradio,

            'rd_foto_id' =>
            $rdFotoId,

            'source_sequence' =>
            $sourceSequence,

            'original_error' =>
            $e->getMessage(),

            'mapping_error' =>
            $mappingError->getMessage(),
          ], 'pacs-migration');
        }

        Yii::error([
          'event' =>
          'pacs_migration_failed',

          'noradio' =>
          $noradio,

          'rd_foto_id' =>
          $rdFotoId,

          'NoFoto' =>
          $foto->NoFoto,

          'source_sequence' =>
          $sourceSequence,

          'parent_study_id' =>
          $orthancStudyId,

          'parent_series_id' =>
          $orthancSeriesId,

          'message' =>
          $e->getMessage(),
        ], 'pacs-migration');

        /*
         * Foto berikutnya tetap diproses.
         */
        continue;
      }
    }
  }

  /**
   * Build DICOM tags.
   */
  private function buildDicomTags(
    array $biodata,
    string $noradio,
    RdFoto $foto,
    bool $isFirstInstance
  ): array {
    /*
     * Instance berikutnya hanya
     * membutuhkan SOPClassUID + InstanceNumber.
     */
    if (!$isFirstInstance) {
      return [
        'SOPClassUID' =>
        '1.2.840.10008.5.1.4.1.1.7',

        'InstanceNumber' =>
        (string) $foto->NoFoto,
      ];
    }

    $studyDate = null;
    $studyTime = null;

    if (!empty($biodata['tglsave'])) {
      $timestamp =
        strtotime(
          $biodata['tglsave']
        );

      if ($timestamp !== false) {
        $studyDate =
          date('Ymd', $timestamp);

        $studyTime =
          date('His', $timestamp);
      }
    }

    $tags = [
      'SOPClassUID' =>
      '1.2.840.10008.5.1.4.1.1.7',

      'PatientID' =>
      (string) $biodata['rm'],

      'PatientName' =>
      (string) $biodata['nama'],

      'StudyDate' =>
      $studyDate ?? date('Ymd'),

      'StudyTime' =>
      $studyTime ?? date('His'),

      'AccessionNumber' =>
      $noradio,

      'StudyID' =>
      $noradio,

      'Modality' =>
      (string) (
        $biodata['procedure_modality']
        ?? ''
      ),

      'SeriesNumber' =>
      '1',

      'InstanceNumber' =>
      (string) $foto->NoFoto,
    ];

    if (!empty($biodata['jk'])) {
      $jk =
        strtoupper(
          trim(
            (string) $biodata['jk']
          )
        );

      if ($jk === 'L') {
        $tags['PatientSex'] = 'M';
      } elseif ($jk === 'P') {
        $tags['PatientSex'] = 'F';
      }
    }

    if (!empty($biodata['tgllahir'])) {
      $birthTimestamp =
        strtotime(
          $biodata['tgllahir']
        );

      if ($birthTimestamp !== false) {
        $tags['PatientBirthDate'] =
          date(
            'Ymd',
            $birthTimestamp
          );
      }
    }

    if (!empty($biodata['procedure_nama'])) {
      $procedureName =
        (string)
        $biodata['procedure_nama'];

      $tags['StudyDescription'] =
        $procedureName;

      $tags['SeriesDescription'] =
        $procedureName;
    }

    if (!empty($biodata['doctor_name'])) {
      $tags['ReferringPhysicianName'] =
        (string)
        $biodata['doctor_name'];
    }

    return $tags;
  }

  /**
   * Save successful migration mapping.
   *
   * Identity:
   *
   *   noradio + source_sequence
   *
   * rd_foto_id tetap disimpan untuk
   * source legacy.
   */
  private function saveSuccessMapping(
    ?RdFoto $foto,
    string $noradio,
    int $sourceSequence,
    ?string $orthancPatientId,
    ?string $orthancStudyId,
    ?string $orthancSeriesId,
    string $orthancInstanceId,
    string $studyInstanceUid,
    string $seriesInstanceUid,
    string $sopInstanceUid
  ): void {
    $existing =
      $this->dbLocal
      ->createCommand(
        'SELECT id
           FROM rd_foto_orthanc
           WHERE noradio = :noradio
             AND source_sequence = :source_sequence
           LIMIT 1'
      )
      ->bindValue(
        ':noradio',
        $noradio
      )
      ->bindValue(
        ':source_sequence',
        $sourceSequence
      )
      ->queryOne();

    $now =
      date('Y-m-d H:i:s');

    $data = [
      'noradio' =>
      $noradio,

      'source_sequence' =>
      $sourceSequence,

      'accession_number' =>
      $noradio,

      'orthanc_patient_id' =>
      $orthancPatientId,

      'orthanc_study_id' =>
      $orthancStudyId,

      'orthanc_series_id' =>
      $orthancSeriesId,

      'orthanc_instance_id' =>
      $orthancInstanceId,

      'study_instance_uid' =>
      $studyInstanceUid,

      'series_instance_uid' =>
      $seriesInstanceUid,

      'sop_instance_uid' =>
      $sopInstanceUid,

      'status' =>
      1,

      'error_message' =>
      null,

      'updated_at' =>
      $now,
    ];

    if ($foto !== null) {
      $data['rd_foto_id'] =
        $foto->getPrimaryKey();
    }

    if ($existing) {
      $this->dbLocal
        ->createCommand()
        ->update(
          'rd_foto_orthanc',
          $data,
          [
            'id' =>
            $existing['id'],
          ]
        )
        ->execute();

      return;
    }

    $data['created_at'] =
      $now;

    /*
     * rd_foto_id tersedia untuk legacy.
     *
     * source sharing akan NULL.
     */
    $this->dbLocal
      ->createCommand()
      ->insert(
        'rd_foto_orthanc',
        $data
      )
      ->execute();
  }

  /**
   * Save error hanya jika mapping lokal
   * sudah ada.
   *
   * Tidak INSERT mapping error baru karena
   * UID DICOM masih NOT NULL pada schema existing.
   */
  private function saveErrorMapping(
    ?RdFoto $foto,
    string $noradio,
    int $sourceSequence,
    string $errorMessage
  ): void {
    $existing =
      $this->dbLocal
      ->createCommand(
        'SELECT id
           FROM rd_foto_orthanc
           WHERE noradio = :noradio
             AND source_sequence = :source_sequence
           LIMIT 1'
      )
      ->bindValue(
        ':noradio',
        $noradio
      )
      ->bindValue(
        ':source_sequence',
        $sourceSequence
      )
      ->queryOne();

    /*
     * Belum ada registry.
     *
     * Jangan INSERT karena UID NOT NULL.
     */
    if (!$existing) {
      Yii::warning([
        'event' =>
        'pacs_migration_mapping_not_saved',

        'noradio' =>
        $noradio,

        'rd_foto_id' =>
        $foto?->getPrimaryKey(),

        'source_sequence' =>
        $sourceSequence,

        'message' =>
        $errorMessage,
      ], 'pacs-migration');

      return;
    }

    $this->dbLocal
      ->createCommand()
      ->update(
        'rd_foto_orthanc',
        [
          'status' => 2,

          'error_message' =>
          $errorMessage,

          'updated_at' =>
          date('Y-m-d H:i:s'),
        ],
        [
          'id' =>
          $existing['id'],
        ]
      )
      ->execute();
  }
}
