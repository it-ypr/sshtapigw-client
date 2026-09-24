<?php

namespace common\services\SshtApiGwClient\extensions\pacs;

use Yii;
use yii\db\Query;
use RuntimeException;

class PacsSharingService
{
  private OrthancService $orthanc;

  private string $sharingPath;

  public function __construct(
    string $sharingPath,
    ?OrthancService $orthanc = null
  ) {
    $this->sharingPath = rtrim($sharingPath, DIRECTORY_SEPARATOR);
    $this->orthanc = $orthanc ?? new OrthancService();
  }

  /**
   * Process seluruh file JPG dari folder sharing.
   *
   * Filename:
   *   {noradio}-{n}.jpg
   *
   * Example:
   *   RAD001-1.jpg
   *   RAD001-2.jpg
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

    $files = glob(
      $this->sharingPath . DIRECTORY_SEPARATOR . '*.jpg'
    );

    if ($files === false) {
      throw new RuntimeException(
        "Gagal membaca folder sharing: {$this->sharingPath}"
      );
    }

    $groups = [];

    foreach ($files as $file) {
      $parsed = $this->parseFilename($file);

      if ($parsed === null) {
        Yii::warning([
          'event' => 'pacs_sharing_invalid_filename',
          'file' => $file,
        ], 'pacs-sharing');

        $result['skipped']++;

        continue;
      }

      $groups[$parsed['noradio']][] = $parsed;
    }

    $result['total_noradio'] = count($groups);

    foreach ($groups as $noradio => $items) {
      $result['total_file'] += count($items);

      $this->processNoradio(
        (string) $noradio,
        $items,
        $result
      );
    }

    return $result;
  }

  /**
   * Process seluruh image untuk satu noradio.
   *
   * 1 Study
   * 1 Series
   * N Instance
   *
   * @param array<int, array{
   *     file:string,
   *     filename:string,
   *     noradio:string,
   *     sequence:int
   * }> $files
   */
  private function processNoradio(
    string $noradio,
    array $files,
    array &$result
  ): void {
    /*
     * Ambil metadata SIMRS.
     */
    $metadata = $this->resolveMetadata($noradio);

    if ($metadata === null) {
      Yii::warning([
        'event' => 'pacs_sharing_metadata_not_found',
        'noradio' => $noradio,
      ], 'pacs-sharing');

      $result['failed'] += count($files);

      return;
    }

    /*
     * Urutkan berdasarkan sequence.
     */
    usort(
      $files,
      static fn(array $a, array $b): int =>
      $a['sequence'] <=> $b['sequence']
    );

    /*
     * Cari Study yang sudah ada.
     */
    $orthancStudyId =
      $this->orthanc->findStudyByAccessionNumber(
        $noradio
      );

    $orthancSeriesId = null;

    /*
     * Kalau Study sudah ada, ambil Series pertama.
     */
    if ($orthancStudyId !== null) {
      $study = $this->orthanc->getStudy(
        $orthancStudyId
      );

      $orthancSeriesId =
        $study['Series'][0] ?? null;

      if ($orthancSeriesId === null) {
        throw new RuntimeException(
          "Study {$orthancStudyId} tidak memiliki Series."
        );
      }
    }

    foreach ($files as $file) {
      try {
        $this->processFile(
          $file,
          $metadata,
          $orthancStudyId,
          $orthancSeriesId
        );

        /*
         * processFile() bisa menghasilkan Study/Series
         * baru pada instance pertama.
         *
         * Karena itu hierarchy kita ambil ulang
         * dari Orthanc setelah create.
         */
        if ($orthancStudyId === null) {
          $orthancStudyId =
            $this->orthanc->findStudyByAccessionNumber(
              $noradio
            );
        }

        if (
          $orthancStudyId !== null
          && $orthancSeriesId === null
        ) {
          $study = $this->orthanc->getStudy(
            $orthancStudyId
          );

          $orthancSeriesId =
            $study['Series'][0] ?? null;
        }

        $result['success']++;
      } catch (\Throwable $e) {
        $result['failed']++;

        Yii::error([
          'event' => 'pacs_sharing_file_failed',
          'noradio' => $noradio,
          'filename' => $file['filename'],
          'sequence' => $file['sequence'],
          'message' => $e->getMessage(),
          'trace' => $e->getTraceAsString(),
        ], 'pacs-sharing');
      }
    }
  }

  /**
   * Process satu image.
   */
  private function processFile(
    array $file,
    array $metadata,
    ?string $orthancStudyId,
    ?string $orthancSeriesId
  ): void {
    $imageBinary = file_get_contents(
      $file['file']
    );

    if ($imageBinary === false) {
      throw new RuntimeException(
        "Gagal membaca file: {$file['file']}"
      );
    }

    $isFirstInstance =
      $orthancSeriesId === null;

    $tags = $this->buildDicomTags(
      $metadata,
      $file['noradio'],
      $file['sequence'],
      $isFirstInstance
    );

    Yii::info([
      'event' => 'pacs_sharing_create_dicom',
      'noradio' => $file['noradio'],
      'filename' => $file['filename'],
      'sequence' => $file['sequence'],
      'is_first_instance' => $isFirstInstance,
      'orthanc_study_id' => $orthancStudyId,
      'orthanc_series_id' => $orthancSeriesId,
    ], 'pacs-sharing');

    $response = $this->orthanc->createDicom(
      $imageBinary,
      $tags,
      $orthancSeriesId
    );

    $orthancInstanceId =
      $response['ID'] ?? null;

    if (empty($orthancInstanceId)) {
      throw new RuntimeException(
        'Orthanc create-dicom tidak mengembalikan ID instance.'
      );
    }

    /*
     * Ambil hierarchy instance.
     */
    $instance = $this->orthanc->getInstance(
      $orthancInstanceId
    );

    $currentSeriesId =
      $instance['ParentSeries']
      ?? $response['ParentSeries']
      ?? null;

    if (empty($currentSeriesId)) {
      throw new RuntimeException(
        'Orthanc instance tidak memiliki ParentSeries.'
      );
    }

    if (
      $orthancSeriesId !== null
      && $currentSeriesId !== $orthancSeriesId
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
     * Validasi Study.
     */
    $series = $this->orthanc->getSeries(
      $currentSeriesId
    );

    $currentStudyId =
      $series['ParentStudy']
      ?? $response['ParentStudy']
      ?? $orthancStudyId
      ?? null;

    if (empty($currentStudyId)) {
      throw new RuntimeException(
        'Orthanc Series tidak memiliki ParentStudy.'
      );
    }

    if (
      $orthancStudyId !== null
      && $currentStudyId !== $orthancStudyId
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
   * Semua validasi Orthanc berhasil.
   *
   * Pindahkan source file ke:
   *
   * sharing/processed/
   *
   * Supaya cron berikutnya tidak memproses
   * file yang sama lagi.
   */
    $this->moveToProcessed(
      $file['file']
    );

    Yii::info([
      'event' => 'pacs_sharing_file_success',
      'noradio' => $file['noradio'],
      'filename' => $file['filename'],
      'sequence' => $file['sequence'],
      'orthanc_instance_id' => $orthancInstanceId,
      'orthanc_series_id' => $currentSeriesId,
      'orthanc_study_id' => $currentStudyId,
    ], 'pacs-sharing');
  }

  /**
   * Pindahkan file yang sudah berhasil
   * diproses ke folder processed.
   */
  private function moveToProcessed(
    string $file
  ): void {
    $processedPath =
      $this->sharingPath
      . DIRECTORY_SEPARATOR
      . 'processed';

    if (!is_dir($processedPath)) {
      if (
        !mkdir(
          $processedPath,
          0775,
          true
        )
        && !is_dir($processedPath)
      ) {
        throw new RuntimeException(
          "Gagal membuat folder processed: {$processedPath}"
        );
      }
    }

    $filename = basename($file);

    $destination =
      $processedPath
      . DIRECTORY_SEPARATOR
      . $filename;

    /*
   * Jangan overwrite file yang sudah ada.
   */
    if (file_exists($destination)) {
      throw new RuntimeException(
        "File tujuan sudah ada: {$destination}"
      );
    }

    if (!rename($file, $destination)) {
      throw new RuntimeException(
        "Gagal memindahkan file ke: {$destination}"
      );
    }

    Yii::info([
      'event' => 'pacs_sharing_file_moved',
      'source' => $file,
      'destination' => $destination,
    ], 'pacs-sharing');
  }

  /**
   * Parse:
   *
   * {noradio}-{n}.jpg
   *
   * Contoh:
   *
   * RAD-2026-001-3.jpg
   *
   * menghasilkan:
   *
   * noradio  = RAD-2026-001
   * sequence = 3
   */
  private function parseFilename(
    string $file
  ): ?array {
    $filename = basename($file);

    if (
      !preg_match(
        '/^(.+)-(\d+)\.jpg$/i',
        $filename,
        $matches
      )
    ) {
      return null;
    }

    $noradio = trim($matches[1]);
    $sequence = (int) $matches[2];

    if ($noradio === '' || $sequence < 1) {
      return null;
    }

    return [
      'file' => $file,
      'filename' => $filename,
      'noradio' => $noradio,
      'sequence' => $sequence,
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
        'rd_biodata.noradio' => $noradio,
        'rd_biodata.ondelete' => 0,
        'rdp.kondisi' => 1,
      ])
      ->one();
  }

  /**
   * Build DICOM tags.
   *
   * Instance pertama:
   *   metadata Patient + Study + Series + Instance.
   *
   * Instance berikutnya:
   *   hanya metadata instance.
   *
   * Metadata Study/Series pada instance berikutnya
   * akan mengikuti Series parent di Orthanc.
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
   * Jangan kirim ulang metadata Study/Series.
   * Parent Series sudah menentukan hierarchy instance.
   */
    if (!$isFirstInstance) {
      return [
        'SOPClassUID' =>
        '1.2.840.10008.5.1.4.1.1.7',

        'InstanceNumber' =>
        (string) $sequence,
      ];
    }

    /*
   * StudyDate / StudyTime
   *
   * Menggunakan rd_biodata.tglsave,
   * sama seperti PacsMigrationService.
   */
    $studyDate = null;
    $studyTime = null;

    if (!empty($metadata['tglsave'])) {
      $timestamp = strtotime(
        $metadata['tglsave']
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
   * Metadata utama.
   */
    $tags = [
      'SOPClassUID' =>
      '1.2.840.10008.5.1.4.1.1.7',

      'PatientID' =>
      (string) ($metadata['rm'] ?? ''),

      'PatientName' =>
      (string) ($metadata['nama'] ?? ''),

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
        $metadata['procedure_modality']
        ?? ''
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
      $jk = strtoupper(
        trim((string) $metadata['jk'])
      );

      // SIMRS:
      // L = Laki-laki
      // P = Perempuan
      //
      // DICOM:
      // M = Male
      // F = Female
      if ($jk === 'L') {
        $tags['PatientSex'] = 'M';
      } elseif ($jk === 'P') {
        $tags['PatientSex'] = 'F';
      }
    }

    /*
   * PatientBirthDate.
   */
    if (!empty($metadata['tgllahir'])) {
      $birthTimestamp = strtotime(
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
    if (!empty($metadata['procedure_nama'])) {
      $procedureName =
        (string) $metadata['procedure_nama'];

      $tags['StudyDescription'] =
        $procedureName;

      $tags['SeriesDescription'] =
        $procedureName;
    }

    /*
   * Referring physician.
   */
    if (!empty($metadata['doctor_name'])) {
      $tags['ReferringPhysicianName'] =
        (string) $metadata['doctor_name'];
    }

    return $tags;
  }
}
