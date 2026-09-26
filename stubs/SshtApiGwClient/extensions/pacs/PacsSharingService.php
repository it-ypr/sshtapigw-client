<?php

namespace common\services\SshtApiGwClient\extensions\pacs;

use Yii;
use yii\db\Query;
use RuntimeException;

/** @package common\services\SshtApiGwClient\extensions\pacs 
 *
 * PacsSharingService
 * services untuk import data dari shared media storage ke orthanc pacs
 */
class PacsSharingService
{
  private OrthancService $orthanc;

  private PacsLockService $lock;

  private string $sharingPath;

  private $dbLocal;

  public function __construct(
    string $sharingPath,
    ?OrthancService $orthanc = null,
    ?PacsLockService $lock = null
  ) {
    $this->sharingPath =
      rtrim(
        $sharingPath,
        DIRECTORY_SEPARATOR
      );

    $this->orthanc =
      $orthanc ?? new OrthancService();

    $this->lock =
      $lock ?? new PacsLockService();

    $this->dbLocal =
      Yii::$app->db;
  }

  /**
   * Process file sharing.
   *
   * Struktur:
   *
   * /sharing/YYYY/MM/DD/{noradio}-{sequence}.jpg
   *
   * Contoh:
   *
   * /sharing/2026/09/25/RAD001-1.jpg
   * /sharing/2026/09/25/RAD001-2.jpg
   *
   * Recovery window:
   *
   *   hari ini
   *   + 2 hari sebelumnya
   */
  public function process(): array
  {
    $result = [
      'total_noradio' => 0,
      'total_file' => 0,
      'success' => 0,
      'failed' => 0,
      'skipped' => 0,
    ];

    if (!is_dir($this->sharingPath)) {
      throw new RuntimeException(
        "Folder sharing tidak ditemukan: {$this->sharingPath}"
      );
    }

    $groups = [];

    /*
     * Scan hari ini + 2 hari sebelumnya.
     */
    for ($i = 0; $i <= 2; $i++) {
      $timestamp =
        strtotime("-{$i} days");

      $datePath =
        $this->sharingPath
        . DIRECTORY_SEPARATOR
        . date('Y', $timestamp)
        . DIRECTORY_SEPARATOR
        . date('m', $timestamp)
        . DIRECTORY_SEPARATOR
        . date('d', $timestamp);

      if (!is_dir($datePath)) {
        continue;
      }

      $files = glob(
        $datePath
          . DIRECTORY_SEPARATOR
          . '*.jpg'
      );

      if ($files === false) {
        Yii::warning([
          'event' =>
          'pacs_sharing_scan_failed',

          'path' =>
          $datePath,
        ], 'pacs-sharing');

        continue;
      }

      foreach ($files as $file) {
        $parsed =
          $this->parseFilename($file);

        if ($parsed === null) {
          Yii::warning([
            'event' =>
            'pacs_sharing_invalid_filename',

            'file' =>
            $file,
          ], 'pacs-sharing');

          $result['skipped']++;

          continue;
        }

        $groups[$parsed['noradio']][] = $parsed;
      }
    }

    $result['total_noradio'] =
      count($groups);

    foreach (
      $groups as $noradio => $items
    ) {
      $result['total_file'] +=
        count($items);

      $this->processNoradio(
        (string) $noradio,
        $items,
        $result
      );
    }

    return $result;
  }

  /**
   * Acquire lock per noradio.
   */
  private function processNoradio(
    string $noradio,
    array $files,
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
        'pacs_sharing_lock_failed',

        'noradio' =>
        $noradio,
      ], 'pacs-sharing');

      return;
    }

    try {
      $this->processNoradioLocked(
        $noradio,
        $files,
        $result
      );
    } finally {
      $this->lock->release(
        $noradio
      );
    }
  }

  /**
   * Process seluruh image untuk satu noradio.
   *
   * Rule:
   *
   *   1 Study
   *   1 Series
   *   N Instance
   */
  private function processNoradioLocked(
    string $noradio,
    array $files,
    array &$result
  ): void {
    /*
     * Ambil metadata SIMRS.
     */
    $metadata =
      $this->resolveMetadata(
        $noradio
      );

    if ($metadata === null) {
      Yii::warning([
        'event' =>
        'pacs_sharing_metadata_not_found',

        'noradio' =>
        $noradio,
      ], 'pacs-sharing');

      $result['failed'] +=
        count($files);

      return;
    }

    /*
     * Urutkan berdasarkan source_sequence.
     */
    usort(
      $files,
      static fn(
        array $a,
        array $b
      ): int =>
      $a['sequence']
        <=>
        $b['sequence']
    );

    /*
     * Cari mapping existing untuk
     * mendapatkan Study/Series.
     */
    $existingMapping =
      (new Query())
      ->from('rd_foto_orthanc')
      ->where([
        'noradio' =>
        $noradio,

        'status' =>
        1,
      ])
      ->andWhere([
        'not',
        [
          'orthanc_study_id' =>
          null,
        ],
      ])
      ->orderBy([
        'id' =>
        SORT_ASC,
      ])
      ->one(
        $this->dbLocal
      );

    $orthancStudyId =
      $existingMapping['orthanc_study_id'] ?? null;

    $orthancSeriesId =
      $existingMapping['orthanc_series_id'] ?? null;

    /*
     * Kalau registry belum punya Study,
     * coba cari langsung di Orthanc.
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
          'pacs_sharing_find_study_failed',

          'noradio' =>
          $noradio,

          'message' =>
          $e->getMessage(),
        ], 'pacs-sharing');
      }
    }

    /*
     * Kalau Study ada tapi Series belum diketahui,
     * ambil Series pertama.
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

        if (
          empty($orthancSeriesId)
        ) {
          throw new RuntimeException(
            "Study {$orthancStudyId} tidak memiliki Series."
          );
        }
      } catch (\Throwable $e) {
        Yii::error([
          'event' =>
          'pacs_sharing_get_study_failed',

          'noradio' =>
          $noradio,

          'orthanc_study_id' =>
          $orthancStudyId,

          'message' =>
          $e->getMessage(),
        ], 'pacs-sharing');

        $result['failed'] +=
          count($files);

        return;
      }
    }

    foreach ($files as $file) {
      /*
       * Identity:
       *
       *   noradio + source_sequence
       */
      $mapping =
        (new Query())
        ->from('rd_foto_orthanc')
        ->where([
          'noradio' =>
          $noradio,

          'source_sequence' =>
          $file['sequence'],
        ])
        ->one(
          $this->dbLocal
        );

      /*
       * Sudah berhasil dibuat?
       *
       * Jangan create ulang.
       */
      if (
        $mapping
        && (int) $mapping['status'] === 1
        && !empty($mapping['orthanc_instance_id'])
      ) {
        $result['skipped']++;

        /*
         * Recovery hierarchy.
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
          'pacs_sharing_skipped',

          'noradio' =>
          $noradio,

          'filename' =>
          $file['filename'],

          'sequence' =>
          $file['sequence'],

          'orthanc_instance_id' =>
          $mapping['orthanc_instance_id'],
        ], 'pacs-sharing');

        continue;
      }

      try {
        /*
         * Kalau belum ada registry,
         * create DICOM.
         */
        $created =
          $this->processFile(
            $file,
            $metadata,
            $orthancStudyId,
            $orthancSeriesId
          );

        /*
         * processFile() mengembalikan
         * hierarchy aktual dari Orthanc.
         */
        $orthancStudyId =
          $created['orthanc_study_id'];

        $orthancSeriesId =
          $created['orthanc_series_id'];

        $result['success']++;
      } catch (\Throwable $e) {
        $result['failed']++;

        /*
         * Kalau registry sudah ada,
         * simpan error.
         *
         * Kalau belum ada, jangan INSERT
         * karena UID masih NOT NULL.
         */
        try {
          $this->saveErrorMapping(
            $noradio,
            (int) $file['sequence'],
            $e->getMessage()
          );
        } catch (\Throwable $mappingError) {
          Yii::error([
            'event' =>
            'pacs_sharing_error_mapping_failed',

            'noradio' =>
            $noradio,

            'filename' =>
            $file['filename'],

            'sequence' =>
            $file['sequence'],

            'original_error' =>
            $e->getMessage(),

            'mapping_error' =>
            $mappingError->getMessage(),
          ], 'pacs-sharing');
        }

        Yii::error([
          'event' =>
          'pacs_sharing_file_failed',

          'noradio' =>
          $noradio,

          'filename' =>
          $file['filename'],

          'sequence' =>
          $file['sequence'],

          'orthanc_study_id' =>
          $orthancStudyId,

          'orthanc_series_id' =>
          $orthancSeriesId,

          'message' =>
          $e->getMessage(),
        ], 'pacs-sharing');

        /*
         * File berikutnya tetap dicoba.
         */
        continue;
      }
    }
  }

  /**
   * Process satu image.
   *
   * Return:
   *
   * [
   *   'orthanc_study_id' => ...,
   *   'orthanc_series_id' => ...,
   *   'orthanc_instance_id' => ...,
   * ]
   */
  private function processFile(
    array $file,
    array $metadata,
    ?string $orthancStudyId,
    ?string $orthancSeriesId
  ): array {
    $imageBinary =
      file_get_contents(
        $file['file']
      );

    if ($imageBinary === false) {
      throw new RuntimeException(
        "Gagal membaca file: {$file['file']}"
      );
    }

    /*
     * Kalau belum ada Series berarti
     * ini instance pertama.
     */
    $isFirstInstance =
      $orthancSeriesId === null;

    $tags =
      $this->buildDicomTags(
        $metadata,
        $file['noradio'],
        $file['sequence'],
        $isFirstInstance
      );

    Yii::info([
      'event' =>
      'pacs_sharing_create_dicom',

      'noradio' =>
      $file['noradio'],

      'filename' =>
      $file['filename'],

      'sequence' =>
      $file['sequence'],

      'is_first_instance' =>
      $isFirstInstance,

      'orthanc_study_id' =>
      $orthancStudyId,

      'orthanc_series_id' =>
      $orthancSeriesId,
    ], 'pacs-sharing');

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
        $imageBinary,
        $tags,
        $orthancSeriesId
      );

    $orthancInstanceId =
      $response['ID'] ?? null;

    if (
      empty($orthancInstanceId)
    ) {
      throw new RuntimeException(
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
      throw new RuntimeException(
        'Orthanc instance tidak memiliki ParentSeries.'
      );
    }

    /*
     * Pastikan Series sama.
     */
    if (
      !empty($orthancSeriesId)
      && $currentSeriesId !==
      $orthancSeriesId
    ) {
      throw new RuntimeException(
        sprintf(
          'Instance masuk ke Series berbeda. Expected=%s Actual=%s',
          $orthancSeriesId,
          $currentSeriesId
        )
      );
    }

    /*
     * GET SERIES
     */
    $series =
      $this->orthanc->getSeries(
        $currentSeriesId
      );

    $currentStudyId =
      $series['ParentStudy']
      ?? $response['ParentStudy']
      ?? $orthancStudyId
      ?? null;

    if (
      empty($currentStudyId)
    ) {
      throw new RuntimeException(
        'Orthanc Series tidak memiliki ParentStudy.'
      );
    }

    /*
     * Pastikan Study sama.
     */
    if (
      !empty($orthancStudyId)
      && $currentStudyId !==
      $orthancStudyId
    ) {
      throw new RuntimeException(
        sprintf(
          'Instance masuk ke Study berbeda. Expected=%s Actual=%s',
          $orthancStudyId,
          $currentStudyId
        )
      );
    }

    /*
     * GET STUDY
     */
    $study =
      $this->orthanc->getStudy(
        $currentStudyId
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
      throw new RuntimeException(
        'UID DICOM tidak lengkap pada hierarchy Orthanc.'
      );
    }

    /*
     * SAVE REGISTRY.
     *
     * rd_foto_id = NULL karena source
     * berasal dari sharing.
     */
    $this->saveSuccessMapping(
      $file['noradio'],
      (int) $file['sequence'],
      $orthancPatientId,
      $currentStudyId,
      $currentSeriesId,
      $orthancInstanceId,
      $studyInstanceUid,
      $seriesInstanceUid,
      $sopInstanceUid
    );

    /*
     * Source JPG TIDAK dipindahkan.
     *
     * File tetap berada di:
     *
     * /sharing/YYYY/MM/DD/
     */

    Yii::info([
      'event' =>
      'pacs_sharing_file_success',

      'noradio' =>
      $file['noradio'],

      'filename' =>
      $file['filename'],

      'sequence' =>
      $file['sequence'],

      'orthanc_instance_id' =>
      $orthancInstanceId,

      'orthanc_series_id' =>
      $currentSeriesId,

      'orthanc_study_id' =>
      $currentStudyId,

      'study_instance_uid' =>
      $studyInstanceUid,

      'series_instance_uid' =>
      $seriesInstanceUid,

      'sop_instance_uid' =>
      $sopInstanceUid,
    ], 'pacs-sharing');

    return [
      'orthanc_study_id' =>
      $currentStudyId,

      'orthanc_series_id' =>
      $currentSeriesId,

      'orthanc_instance_id' =>
      $orthancInstanceId,
    ];
  }

  /**
   * Parse:
   *
   * {noradio}-{sequence}.jpg
   *
   * Contoh:
   *
   * RAD-2026-001-3.jpg
   *
   * =>
   *
   * noradio  = RAD-2026-001
   * sequence = 3
   */
  private function parseFilename(
    string $file
  ): ?array {
    $filename =
      basename($file);

    if (
      !preg_match(
        '/^(.+)-(\d+)\.jpg$/i',
        $filename,
        $matches
      )
    ) {
      return null;
    }

    $noradio =
      trim($matches[1]);

    $sequence =
      (int) $matches[2];

    if (
      $noradio === ''
      || $sequence < 1
    ) {
      return null;
    }

    return [
      'file' =>
      $file,

      'filename' =>
      $filename,

      'noradio' =>
      $noradio,

      'sequence' =>
      $sequence,
    ];
  }

  /**
   * Resolve metadata dari DB SIMRS.
   */
  private function resolveMetadata(
    string $noradio
  ): ?array {
    return (new Query())
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
      ->where([
        'rd_biodata.noradio' =>
        $noradio,

        'rd_biodata.ondelete' =>
        0,

        'rdp.kondisi' =>
        1,
      ])
      ->one();
  }

  /**
   * Build DICOM tags.
   */
  private function buildDicomTags(
    array $metadata,
    string $noradio,
    int $sequence,
    bool $isFirstInstance
  ): array {
    /*
     * Instance berikutnya.
     *
     * Metadata Study/Series diwariskan
     * dari Parent Series.
     */
    if (!$isFirstInstance) {
      return [
        'SOPClassUID' =>
        '1.2.840.10008.5.1.4.1.1.7',

        'InstanceNumber' =>
        (string) $sequence,
      ];
    }

    $studyDate = null;
    $studyTime = null;

    if (!empty($metadata['tglsave'])) {
      $timestamp =
        strtotime(
          $metadata['tglsave']
        );

      if ($timestamp !== false) {
        $studyDate =
          date(
            'Ymd',
            $timestamp
          );

        $studyTime =
          date(
            'His',
            $timestamp
          );
      }
    }

    $tags = [
      'SOPClassUID' =>
      '1.2.840.10008.5.1.4.1.1.7',

      'PatientID' =>
      (string) (
        $metadata['rm'] ?? ''
      ),

      'PatientName' =>
      (string) (
        $metadata['nama'] ?? ''
      ),

      'StudyDate' =>
      $studyDate
        ?? date('Ymd'),

      'StudyTime' =>
      $studyTime
        ?? date('His'),

      'AccessionNumber' =>
      $noradio,

      'StudyID' =>
      $noradio,

      'Modality' =>
      (string) (
        $metadata['procedure_modality'] ?? ''
      ),

      'SeriesNumber' =>
      '1',

      'InstanceNumber' =>
      (string) $sequence,
    ];

    /*
     * PatientSex.
     */
    if (!empty($metadata['jk'])) {
      $jk =
        strtoupper(
          trim(
            (string)
            $metadata['jk']
          )
        );

      if ($jk === 'L') {
        $tags['PatientSex'] =
          'M';
      } elseif ($jk === 'P') {
        $tags['PatientSex'] =
          'F';
      }
    }

    /*
     * PatientBirthDate.
     */
    if (!empty($metadata['tgllahir'])) {
      $birthTimestamp =
        strtotime(
          $metadata['tgllahir']
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
     * Study / Series description.
     */
    if (
      !empty($metadata['procedure_nama'])
    ) {
      $procedureName =
        (string)
        $metadata['procedure_nama'];

      $tags['StudyDescription'] =
        $procedureName;

      $tags['SeriesDescription'] =
        $procedureName;
    }

    /*
     * Referring physician.
     */
    if (
      !empty($metadata['doctor_name'])
    ) {
      $tags['ReferringPhysicianName'] =
        (string)
        $metadata['doctor_name'];
    }

    return $tags;
  }

  /**
   * Save successful sharing mapping.
   *
   * rd_foto_id sengaja NULL karena
   * source berasal dari sharing.
   *
   * Identity:
   *
   *   noradio + source_sequence
   */
  private function saveSuccessMapping(
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

      'rd_foto_id' =>
      null,

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
      /*
       * Jangan overwrite rd_foto_id kalau
       * ternyata mapping ini sebelumnya
       * berasal dari legacy.
       *
       * Ini penting kalau sharing menemukan
       * mapping legacy yang sudah ada.
       */
      unset(
        $data['rd_foto_id']
      );

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

    $this->dbLocal
      ->createCommand()
      ->insert(
        'rd_foto_orthanc',
        $data
      )
      ->execute();
  }

  /**
   * Save error hanya jika registry
   * sudah ada.
   *
   * Tidak INSERT baru karena UID
   * masih NOT NULL pada schema existing.
   */
  private function saveErrorMapping(
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

    if (!$existing) {
      Yii::warning([
        'event' =>
        'pacs_sharing_mapping_not_saved',

        'noradio' =>
        $noradio,

        'source_sequence' =>
        $sourceSequence,

        'message' =>
        $errorMessage,
      ], 'pacs-sharing');

      return;
    }

    $this->dbLocal
      ->createCommand()
      ->update(
        'rd_foto_orthanc',
        [
          'status' =>
          2,

          'error_message' =>
          $errorMessage,

          'updated_at' =>
          date(
            'Y-m-d H:i:s'
          ),
        ],
        [
          'id' =>
          $existing['id'],
        ]
      )
      ->execute();
  }
}
