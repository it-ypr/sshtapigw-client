<?php

namespace common\services\SshtApiGwClient\extensions;

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
    /*
     * TODO:
     * Idempotency check sebelum create DICOM.
     *
     * Untuk sementara kita belum menghapus file
     * sampai mekanisme duplicate benar-benar final.
     */
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

    /*
     * Instance pertama:
     *
     * Parent = null
     *
     * Instance berikutnya:
     *
     * Parent = Series ID
     */
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

    $currentStudyId = null;

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
     * Kalau berhasil, file boleh dipindahkan / dihapus.
     *
     * Untuk sekarang kita tahan dulu sampai
     * mekanisme idempotency selesai.
     */
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
        'rd_biodata.ondelete' => '0',
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
    $tags = [
      'PatientID' => (string) $metadata['rm'],
      'PatientName' => (string) $metadata['nama'],

      'AccessionNumber' => $noradio,
      'StudyID' => $noradio,

      'InstanceNumber' => $sequence,

      'SOPClassUID' =>
      '1.2.840.10008.5.1.4.1.1.7',
    ];

    /*
     * PatientSex.
     */
    if (!empty($metadata['jk'])) {
      $jk = strtoupper(trim((string) $metadata['jk']));

      // format yang valid di orthanc pake bhs ingris (Laki-laki = M, permempuan = F)
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
      $tags['PatientBirthDate'] =
        date(
          'Ymd',
          strtotime($metadata['tgllahir'])
        );
    }

    /*
     * Metadata Study/Series hanya perlu
     * diberikan pada instance pertama.
     *
     * Instance berikutnya akan inherit dari
     * Series parent di Orthanc.
     */
    if ($isFirstInstance) {
      if (!empty($metadata['procedure_modality'])) {
        $tags['Modality'] =
          (string) $metadata['procedure_modality'];
      }

      if (!empty($metadata['procedure_nama'])) {
        $tags['StudyDescription'] =
          (string) $metadata['procedure_nama'];

        $tags['SeriesDescription'] =
          (string) $metadata['procedure_nama'];
      }

      if (!empty($metadata['doctor_name'])) {
        $tags['ReferringPhysicianName'] =
          (string) $metadata['doctor_name'];
      }

      $tags['SeriesNumber'] = 1;
    }

    return $tags;
  }
}
