<?php

namespace common\services\SshtApiGwClient\extensions;

use frontend\models\RdFoto;
use Yii;
use yii\db\Query;

class PacsMigrationService
{
  private OrthancService $orthanc;

  public function __construct(?OrthancService $orthanc = null)
  {
    $this->orthanc = $orthanc ?? new OrthancService();
  }

  /**
   * Migrate seluruh foto radiologi seko rd_foto berdasarkan tanggal pemeriksaan.
   */
  public function migrateByDate(string $tanggal): array
  {
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

    $result['total_noradio'] = count($noradios);

    foreach ($noradios as $noradio) {
      $this->migrateNoradio(
        (string) $noradio,
        $result
      );
    }

    return $result;
  }

  /**
   * Migrate seluruh foto untuk satu noradio.
   *
   * in-case ono temuan 1 noradio untuk (n) rd_foto.foto
   *
   *   1 Study
   *   1 Series
   *   N Instance
   */
  private function migrateNoradio(
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
      ])
      ->one();

    if (!$biodata) {
      Yii::warning([
        'event' => 'pacs_migration_biodata_not_found',
        'noradio' => $noradio,
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
        'event' => 'pacs_migration_foto_not_found',
        'noradio' => $noradio,
      ], 'pacs-migration');

      return;
    }

    $result['total_foto'] += count($fotos);

    /*
     * Cari mapping yang sudah ada untuk noradio ini.
     *
     * Jika migration legacy db sebelumnya sudah berhasil membuat Study/Series,
     * bisa melanjutkan instance berikutnya ke Series tersebut.
     */
    $existingMapping = (new Query())
      ->from('rd_foto_orthanc')
      ->where([
        'noradio' => $noradio,
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
      ->one();

    $orthancStudyId =
      $existingMapping['orthanc_study_id']
      ?? null;

    $orthancSeriesId =
      $existingMapping['orthanc_series_id']
      ?? null;

    /*
     * Jika mapping lokal tidak punya Study ID, coba cari langsung
     * ke Orthanc menggunakan AccessionNumber.
     */
    if (empty($orthancStudyId)) {
      try {
        $orthancStudyId =
          $this->orthanc->findStudyByAccessionNumber(
            $noradio
          );
      } catch (\Throwable $e) {
        Yii::warning([
          'event' => 'pacs_migration_find_study_failed',
          'noradio' => $noradio,
          'message' => $e->getMessage(),
        ], 'pacs-migration');
      }
    }

    /*
     * Kalau Study sudah ditemukan tetapi Series belum diketahui,
     * ambil hierarchy Study dari Orthanc.
     */
    if (
      !empty($orthancStudyId)
      && empty($orthancSeriesId)
    ) {
      try {
        $study = $this->orthanc->getStudy(
          $orthancStudyId
        );

        $orthancSeriesId =
          $study['Series'][0]
          ?? null;

        if (empty($orthancSeriesId)) {
          Yii::warning([
            'event' => 'pacs_migration_existing_study_without_series',
            'noradio' => $noradio,
            'orthanc_study_id' => $orthancStudyId,
          ], 'pacs-migration');
        }
      } catch (\Throwable $e) {
        Yii::warning([
          'event' => 'pacs_migration_get_existing_study_failed',
          'noradio' => $noradio,
          'orthanc_study_id' => $orthancStudyId,
          'message' => $e->getMessage(),
        ], 'pacs-migration');
      }
    }

    foreach ($fotos as $foto) {
      $rdFotoId = $foto->getPrimaryKey();

      /*
       * Cari mapping berdasarkan rd_foto_id.
       */
      $mapping = (new Query())
        ->from('rd_foto_orthanc')
        ->where([
          'rd_foto_id' => $rdFotoId,
        ])
        ->one();

      /*
       * Jika sudah sukses dan instance ID tersedia,
       * jangan kirim ulang ke Orthanc.
       */
      if (
        $mapping
        && (int) $mapping['status'] === 1
        && !empty($mapping['orthanc_instance_id'])
      ) {
        $result['skipped']++;

        /*
         * Recovery hierarchy dari mapping lokal.
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
          'event' => 'pacs_migration_skipped',
          'noradio' => $noradio,
          'rd_foto_id' => $rdFotoId,
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
       * Instance pertama vs instance berikutnya.
       *
       * Instance pertama:
       *   Parent = null
       *   Tags = metadata lengkap
       *
       * Instance berikutnya:
       *   Parent = Series ID
       *   Tags = InstanceNumber 
       */
      $isFirstInstance =
        empty($orthancSeriesId);

      $tags = $this->buildDicomTags(
        $biodata,
        $noradio,
        $foto,
        $isFirstInstance
      );

      try {
        Yii::info([
          'event' => 'pacs_migration_create_dicom',
          'noradio' => $noradio,
          'rd_foto_id' => $rdFotoId,
          'NoFoto' => $foto->NoFoto,
          'is_first_instance' =>
          $isFirstInstance,
          'parent_study_id' =>
          $orthancStudyId,
          'parent_series_id' =>
          $orthancSeriesId,
          'tags' => $tags,
        ], 'pacs-migration');

        /*
         * CREATE DICOM
         *
         * Foto pertama:
         *   Parent = null
         *
         * Foto berikutnya:
         *   Parent = Series ID
         */
        $response = $this->orthanc->createDicom(
          $foto->foto,
          $tags,
          $orthancSeriesId
        );

        $orthancInstanceId =
          $response['ID']
          ?? null;

        if (empty($orthancInstanceId)) {
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

        if (empty($currentSeriesId)) {
          throw new \RuntimeException(
            'Orthanc instance tidak memiliki ParentSeries.'
          );
        }

        /*
         * Pastikan & ceking instance udah masuk ke Series yang benarr.
         *
         * Untuk instance pertama, currentSeriesId menjadi
         * Series utama untuk noradio ini.
         *
         * Untuk instance berikutnya, currentSeriesId harus sama juga
         * dengan orthancSeriesId.
         */
        if (
          !empty($orthancSeriesId)
          && $currentSeriesId !== $orthancSeriesId
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

        if (empty($currentStudyId)) {
          throw new \RuntimeException(
            'Orthanc series tidak memiliki ParentStudy.'
          );
        }

        /*
         * Pastikan Study ne tetap sama.
         */
        if (
          !empty($orthancStudyId)
          && $currentStudyId !== $orthancStudyId
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
         * Ambil UID dari resource masing-masing.
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
            'event' => 'pacs_migration_uid_incomplete',
            'noradio' => $noradio,
            'rd_foto_id' => $rdFotoId,
            'orthanc_instance_id' =>
            $orthancInstanceId,
            'orthanc_series_id' =>
            $orthancSeriesId,
            'orthanc_study_id' =>
            $orthancStudyId,
            'instance_main_dicom_tags' =>
            $instance['MainDicomTags']
              ?? null,
            'series_main_dicom_tags' =>
            $series['MainDicomTags']
              ?? null,
            'study_main_dicom_tags' =>
            $study['MainDicomTags']
              ?? null,
          ], 'pacs-migration');

          throw new \RuntimeException(
            'UID DICOM tidak lengkap pada hierarchy Orthanc.'
          );
        }

        /*
         * SAVE MAPPING METADATA ORTHANC ne
         */
        $this->saveSuccessMapping(
          $foto,
          $noradio,
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
          'event' => 'pacs_migration_success',
          'noradio' => $noradio,
          'rd_foto_id' => $rdFotoId,
          'NoFoto' => $foto->NoFoto,
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

        /*
         * Penting lur:
         * saveErrorMapping() hanya UPDATE jika mapping sudah ada.
         *
         * Ini untk mencegah INSERT gagal karena kolom UID
         * di rd_foto_orthanc adalah NOT NULL.
         */
        try {
          $this->saveErrorMapping(
            $foto,
            $noradio,
            $e->getMessage()
          );
        } catch (\Throwable $mappingError) {
          Yii::error([
            'event' =>
            'pacs_migration_error_mapping_failed',
            'noradio' => $noradio,
            'rd_foto_id' => $rdFotoId,
            'original_error' =>
            $e->getMessage(),
            'mapping_error' =>
            $mappingError->getMessage(),
          ], 'pacs-migration');
        }

        Yii::error([
          'event' =>
          'pacs_migration_failed',
          'noradio' => $noradio,
          'rd_foto_id' => $rdFotoId,
          'NoFoto' => $foto->NoFoto,
          'parent_study_id' =>
          $orthancStudyId,
          'parent_series_id' =>
          $orthancSeriesId,
          'message' =>
          $e->getMessage(),
        ], 'pacs-migration');

        /*
         * Jangan menghentikan foto berikutnya.
         *
         * Contoh:
         *   foto 1 gagal
         *   foto 2 tetap dicoba.
         */
        continue;
      }
    }
  }

  /**
   * Build DICOM tags.
   *
   * Instance pertama:
   *   metadata Patient + Study + Series + Instance.
   *
   * Instance berikutnya:
   *   hanya InstanceNumber.
   *
   * penting misalll ono case karena Patient/Study/Series tags
   * sudah diwariskan dari Parent Series oleh Orthanc.
   */
  private function buildDicomTags(
    array $biodata,
    string $noradio,
    RdFoto $foto,
    bool $isFirstInstance
  ): array {

    /*
     * Instance berikutnya.
     *
     * Jangan kirim:
     *   PatientID
     *   PatientName
     *   AccessionNumber
     *   StudyID
     *   StudyDate
     *   StudyTime
     *   Modality
     *   SeriesNumber
     *   SeriesDescription
     *   dll.
     *
     * Semua sudah diwariskan dari Series parent.
     */
    if (!$isFirstInstance) {
      return [
        // sauce:
        // https://dicom.nema.org/dicom/2013/output/chtml/part04/sect_i.4.html
        'SOPClassUID' =>
        '1.2.840.10008.5.1.4.1.1.7',

        'InstanceNumber' =>
        (string) $foto->NoFoto,
      ];
    }

    /*
     * StudyDate / StudyTime
     *
     * Menggunakan rd_biodata.tglsave sesuai keputusan migration.
     */
    $studyDate = null;
    $studyTime = null;

    if (!empty($biodata['tglsave'])) {
      $timestamp = strtotime(
        $biodata['tglsave']
      );

      if ($timestamp !== false) {
        $studyDate = date(
          'Ymd',
          $timestamp
        );

        $studyTime = date(
          'His',
          $timestamp
        );
      }
    }

    /*
     * Metadata utama
     */
    $tags = [
      // sauce:
      // https://dicom.nema.org/dicom/2013/output/chtml/part04/sect_i.4.html
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

    /*
     * PatientSex
     */
    if (!empty($biodata['jk'])) {
      $jk = strtoupper(trim((string) $biodata['jk']));

      // format yang valid di orthanc pake bhs ingris (Laki-laki = M, permempuan = F)
      if ($jk === 'L') {
        $tags['PatientSex'] = 'M';
      } elseif ($jk === 'P') {
        $tags['PatientSex'] = 'F';
      }
    }

    /*
     * PatientBirthDate
     */
    if (!empty($biodata['tgllahir'])) {
      $birthTimestamp = strtotime(
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

    /*
     * Study / Series description
     */
    if (!empty($biodata['procedure_nama'])) {
      $procedureName =
        (string) $biodata['procedure_nama'];

      $tags['StudyDescription'] =
        $procedureName;

      $tags['SeriesDescription'] =
        $procedureName;
    }

    /*
     * Referring physician
     */
    if (!empty($biodata['doctor_name'])) {
      $tags['ReferringPhysicianName'] =
        (string) $biodata['doctor_name'];
    }

    return $tags;
  }

  /**
   * Save successful migration mapping.
   */
  private function saveSuccessMapping(
    RdFoto $foto,
    string $noradio,
    ?string $orthancPatientId,
    ?string $orthancStudyId,
    ?string $orthancSeriesId,
    string $orthancInstanceId,
    string $studyInstanceUid,
    string $seriesInstanceUid,
    string $sopInstanceUid
  ): void {
    $existing = Yii::$app->db
      ->createCommand(
        'SELECT id
         FROM rd_foto_orthanc
         WHERE rd_foto_id = :rd_foto_id
         LIMIT 1'
      )
      ->bindValue(
        ':rd_foto_id',
        $foto->getPrimaryKey()
      )
      ->queryOne();

    $now =
      date('Y-m-d H:i:s');

    $data = [
      'noradio' =>
      $noradio,

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

    if ($existing) {
      Yii::$app->db
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

    $data['rd_foto_id'] =
      $foto->getPrimaryKey();

    $data['created_at'] =
      $now;

    Yii::$app->db
      ->createCommand()
      ->insert(
        'rd_foto_orthanc',
        $data
      )
      ->execute();
  }

  /**
   * Save error hanya jika mapping lokal sudah ada.
   *
   * Tidak melakukan INSERT baru karena:
   *
   *   study_instance_uid
   *   series_instance_uid
   *   sop_instance_uid
   *
   * adalah NOT NULL di database.
   */
  private function saveErrorMapping(
    RdFoto $foto,
    string $noradio,
    string $errorMessage
  ): void {
    $existing = Yii::$app->db
      ->createCommand(
        'SELECT id
         FROM rd_foto_orthanc
         WHERE rd_foto_id = :rd_foto_id
         LIMIT 1'
      )
      ->bindValue(
        ':rd_foto_id',
        $foto->getPrimaryKey()
      )
      ->queryOne();

    /*
     * Tidak ada mapping sebelumnya.
     *
     * Jangan INSERT karena UID wajib NOT NULL.
     *
     * Error tetap dicatat ke Yii log.
     */
    if (!$existing) {
      Yii::warning([
        'event' =>
        'pacs_migration_mapping_not_saved',

        'noradio' =>
        $noradio,

        'rd_foto_id' =>
        $foto->getPrimaryKey(),

        'message' =>
        $errorMessage,
      ], 'pacs-migration');

      return;
    }

    Yii::$app->db
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
