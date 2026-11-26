<?php

namespace common\services\SshtApiGwClient\util;

use Carbon\Carbon;
use Jstewmc\Rtf\Document;
use Yii;

class SshtApiUtil
{
  public static function genDebugContext(array $context)
  {
    return [
      'method' => $context[0],
      'url' => $context[1],
    ];
  }

  public static function parseExpertiseRdHasilRtf($rtfContent)
  {
    if (empty($rtfContent))
      return ["", ""];

    try {
      // Jika data diawali format RTF, gunakan parser
      if (strpos($rtfContent, '{\rtf') === 0) {
        $document = new Document($rtfContent);
        $text = $document->write('text'); // Convert ke plain text
      } else {
        // Jika ternyata plain text biasa dari database
        $text = $rtfContent;
      }
    } catch (\Exception $e) {
      // Fallback jika parser gagal karena format RTF korup
      $text = strip_tags($rtfContent);
      Yii::error("RTF Parsing Error: " . $e->getMessage());
    }

    // Bersihkan sisa-sisa karakter whitespace yang aneh
    $text = trim($text);

    // Pemisahan Observation (Temuan) dan Impression (Kesan)
    // Menggunakan regex case-insensitive untuk keyword "KESAN"
    $pattern = '/(k\s*e\s*s\s*a\s*n\s*:?)/i';
    $parts = preg_split($pattern, $text, 2, PREG_SPLIT_DELIM_CAPTURE);

    // Index 0: Teks sebelum kata "KESAN" (Temuan/Observation)
    // Index 1: Kata "KESAN" itu sendiri (karena pakai PREG_SPLIT_DELIM_CAPTURE)
    // Index 2: Teks setelah kata "KESAN" (Kesan/Impression)

    $observation = isset($parts[0]) ? trim($parts[0]) : $text;
    $impression = isset($parts[2]) ? trim($parts[2]) : "";

    // Normalisasi line break agar tidak terlalu renggang (seperti di python re.sub)
    return [
      preg_replace('/\n\s*\n/', "\n", $observation),
      preg_replace('/\n\s*\n/', "\n", $impression)
    ];
  }

  /**
   * parsing kolom textisi di tabel mr_medis_rtf
   * @param mixed $text
   * @return array<float|int|string>
   */
  public static function parseObservation($text)
  {
    $result = [];

    // TD
    if (preg_match('/^\s*(TD|Tensi|BP)\s*[:;]\s*(\d+)\s*\/\s*(\d+)/im', $text, $m)) {
      $result['systolic'] = (int) $m[2];
      $result['diastolic'] = (int) $m[3];
    }

    // Heart rate - Nadi
    if (preg_match('/^\s*(N|Nadi)\s*[:;]\s*(\d+)/im', $text, $m)) {
      $result['heart_rate'] = (int) $m[2];
    }

    // Temperature - Suhu (S)
    if (preg_match('/^\s*(S|Suhu)\s*[:;]\s*([\d.]+)/im', $text, $m)) {
      $result['body_temp'] = (float) $m[2];
    }

    // Respiration Rate / Pernafasan (RR)
    if (preg_match('/RR\s*[:;]\s*(\d+)/i', $text, $m)) {
      $result['respiratory_rate'] = (int) $m[1];
    }

    // GDS
    if (preg_match('/^\s*GDS\s*[:;]\s*(\d+)/im', $text, $m)) {
      $result['gds'] = (int) $m[1];
    }

    // Height (TB: 150 cm)
    if (preg_match('/^\s*(TB|TINGGI\s*BADAN)\s*[:;]\s*([\d.]+)/im', $text, $m)) {
      $result['height_cm'] = (float) $m[2];
    }

    // Weight (BB: 50 kg)
    if (preg_match('/^\s*(BB|BERAT\s*BADAN)\s*[:;]\s*([\d.]+)/im', $text, $m)) {
      $result['weight_kg'] = (float) $m[2];
    }

    // // // SpO2
    // // if (preg_match('/SpO2\s*[:;]\s*(\d+)/i', $text, $m)) {
    // //   $result['spo2'] = (int) $m[1];
    // // }
    //
    // // Kajian Umum (KU)
    // // if (preg_match('/KU\s*[:;]\s*(.+)/i', $text, $m))
    // // {
    // //   $line = trim(explode("\n", $m[1])[0]);
    // //   $result['general_condition'] = $line;
    // // }

    return $result;
  }

  public static function genIdentifierResepMedication($tgl_param, $resep_param, $index): object
  {
    if ($index == 0) {
      $codedate = Carbon::parse($tgl_param)->format('Ymd');
      $idnresep = (string)$codedate . '-' . (string) $resep_param;

      return (object) [
        "identifier_noresep" => $idnresep,
        "identifier_noresep_index" => $idnresep . "-" . "1"
      ];
    }

    $codedate = (string) Carbon::parse($tgl_param)->format('Ymd');
    $idnresep = $codedate . '-' . (string) $resep_param;

    return (object) [
      "identifier_noresep" => $idnresep,
      "identifier_noresep_index" => $idnresep . "-" . (string) $index + 1
    ];
  }

  public static function genAturanPakaiObat(
    string $kali = '', // 3 , 1-2 , 2
    string $hari = '', // 1 , 10, OLES
    string $sediaan = '', // tablet, ML , salep
    string $waktu = '' // sesudah makan, Sesudah makan,
  ): string {
    if (empty(trim($kali)) && empty(trim($hari))) {
      return "-";
    }
    // 3 x 1 Tablet sesudah makan
    return (string) trim($kali) . ' X ' . trim($hari) . ' ' . trim($sediaan) . ' ' . trim($waktu);
  }

  public static function parseDosisFrekuensiLocal(string $kali): string
  {
    // if (str_contains($kali, "/")) {
    //   $dosisFrekuensi = explode('/', (string) trim($kali));
    //   return $dosisFrekuensi[0];
    // }
    // helper untuk parse frekuensi dosis yang int nilai misal: 3
    // di local yang format varchar
    // untuk parsing misal $kali itu ada case bentukannya: 1-2
    $dosisFrekuensi = explode('-', (string) trim($kali));
    // $dosisHarian = str_split($kali);
    return (string) $dosisFrekuensi[0];
  }

  // rule: hari tidak boleh kosong, kalau bentukan pecahan harus pake decimal 0.x
  // public static function parseDosisHarianLocal(string $hari)
  // {
  //   // 1. cek kosong atau tidak -> 1
  //   // 2. cek ada alfabeth -> 1
  //   // 3. cek apakah data hanya "-"
  //   // 3.1. true -> 1
  //   // 4. cek apakah string length lebih dari 2, dan ada "/" (untuk string pecahan)
  //   // 4.1. true -> parse jadi float
  //   // 5. return float
  // }

  /* 
   * Parse Bed location untuk query 
   *
   * exp: '%{nama_ruang}-room-b{nama_bed}-bed'
   */
  public static function parseLocationBed($ruper, $ruang)
  {
    return (string) strtolower(str_replace(' ', '', trim($ruper))) . '-room-b' . trim($ruang) . '-bed';
  }

  // kalau butuh parser untuk clinicalImpression - riwayat perjalanan penyakit taruh sini..
  public static function parseClinicalImpressionRiwayatPerjalananPenyakit() {}

  /* 
   * Grouping Medication/obat dari query
   *
   * untuk membagi identifier dan kelompok/jenis tipe obat
   */
  public static function groupMedicationRows(array $rows): array
  {
    // Mapping kode cangkang kode obat racikan.
    // Case: rsprin
    // nama, kode_brg, Id
    // 'RACIKAN PUYER', 'LD0604', '27239',
    // 'RACIKAN CAPSUL', 'LD0605', '27240',
    // 'RACIKAN DRANK', 'LD0614', '27241',
    // 'RACIKAN IN POT', 'Z2005515', '28930',
    // 'RACIKAN POT KOSMETIK', 'Z2020175', '35003',
    // 'RACIKAN POT KOSMETIK', 'Z2020175', '35003',
    // 'RACIKAN BOTOL', 'Z2021089', '35528',
    // 'RACIKAN SALEP/KRIM', 'LD0616', '35529'
    $shellCodes = [
      'LD0604',
      'LD0605',
      'LD0614',
      'Z2005515',
      'Z2020175',
      'Z2021089',
      'LD0616',
    ];

    // bungkus atau cangkang untuk obat racik non-dtd "EP"
    $shellCodesNonDtd = [
      'LD0614', // RACIKAN DRANK
      'Z2020175', // RACIKAN POT KOSMETIK
    ];

    /*
     * STEP 1.
     * Urutkan data sumber berdasarkan Id.
     */
    usort($rows, static function ($a, $b) {
      return (int) ($a['Id'] ?? 0)
        <=> (int) ($b['Id'] ?? 0);
    });

    /*
     * STEP 2.
     * Rekonsiliasi mutasi berdasarkan urutan Id.
     *
     * Baris negatif mengoreksi kemunculan positif sebelumnya
     * yang paling dekat, dengan tanggal + RM + resep + kode_brg sama.
     *
     * Setiap kemunculan positif dipertahankan terpisah.
     * Ini penting agar dua cangkang dengan kode sama tidak digabung.
     */

    $activeRows = [];
    $positiveOccurrences = [];
    $unclassified = [];

    foreach ($rows as $row) {
      $kode = trim((string) ($row['kode_brg'] ?? ''));

      $key = implode('|', [
        (string) ($row['tanggal'] ?? ''),
        (string) ($row['rm'] ?? ''),
        (string) ($row['resep'] ?? ''),
        $kode,
      ]);

      $jumlah = (float) ($row['jumlah'] ?? 0);

      if ($jumlah > 0) {
        // Simpan setiap kemunculan sebagai baris tersendiri.
        $row['jumlah'] = $jumlah;
        $activeIndex = count($activeRows);

        $activeRows[] = $row;
        $positiveOccurrences[$key][] = $activeIndex;

        continue;
      }

      if ($jumlah == 0.0) {
        continue;
      }

      /*
       * Jumlah negatif:
       * koreksi kemunculan positif sebelumnya yang paling dekat.
       * Jika tidak cukup, lanjutkan ke kemunculan sebelumnya.
       */
      $remainingCorrection = abs($jumlah);
      $occurrences = $positiveOccurrences[$key] ?? [];

      for (
        $i = count($occurrences) - 1;
        $i >= 0 && $remainingCorrection > 0;
        $i--
      ) {
        $activeIndex = $occurrences[$i];

        $available = (float) (
          $activeRows[$activeIndex]['jumlah'] ?? 0
        );

        if ($available <= 0) {
          continue;
        }

        $deduction = min($available, $remainingCorrection);

        $activeRows[$activeIndex]['jumlah'] =
          $available - $deduction;

        $remainingCorrection -= $deduction;
      }

      /*
       * Koreksi yang tidak memiliki target dicatat untuk audit.
       * Jangan diam-diam mengurangi item dengan kode berbeda.
       */
      if ($remainingCorrection > 0) {
        $unclassified[] = [
          'reason' => 'UNMATCHED_NEGATIVE_MUTATION',
          'row' => $row,
          'unmatched_quantity' => $remainingCorrection,
        ];
      }
    }

    // Buang baris yang jumlah akhirnya sudah nol.
    $normalized = [];

    foreach ($activeRows as $row) {
      $jumlah = (float) ($row['jumlah'] ?? 0);

      if ($jumlah <= 0) {
        continue;
      }

      $row['jumlah'] = (string) $jumlah;
      $normalized[] = $row;
    }

    usort($normalized, static function ($a, $b) {
      return (int) ($a['Id'] ?? PHP_INT_MAX)
        <=>
        (int) ($b['Id'] ?? PHP_INT_MAX);
    });

    /*
     * STEP 3.
     * Pisahkan data per resep.
     * Identifier index nantinya dihitung per resep.
     */
    $prescriptions = [];

    foreach ($normalized as $row) {
      $prescriptionKey = implode('|', [
        (string) ($row['tanggal'] ?? ''),
        (string) ($row['rm'] ?? ''),
        (string) ($row['resep'] ?? ''),
      ]);

      $prescriptions[$prescriptionKey][] = $row;
    }

    $items = [];
    // $unclassified = [];


    foreach ($prescriptions as $prescriptionRows) {
      /*
   * STEP 4–6.
   * Grouping berdasarkan urutan Id:
   * - NC menjadi item individual.
   * - Bahan tanpa aturan pakai ditampung sementara.
   * - Ketika shell ditemukan, bahan yang tertampung
   *   menjadi komposisi racikan shell tersebut.
   */

      // Pastikan urutan sumber konsisten.
      usort($prescriptionRows, static function ($a, $b) {
        return (int) ($a['Id'] ?? PHP_INT_MAX)
          <=> (int) ($b['Id'] ?? PHP_INT_MAX);
      });

      $pendingIngredients = [];

      foreach ($prescriptionRows as $row) {
        $kode = trim((string) ($row['kode_brg'] ?? ''));

        $isShell = in_array(
          $kode,
          $shellCodes,
          true
        );

        $hasAturanPakai =
          trim((string) ($row['kali'] ?? '')) !== ''
          || trim((string) ($row['hari'] ?? '')) !== '';

        /*
     * Shell diperiksa dahulu karena shell juga
     * memiliki aturan pakai.
     */
        if ($isShell) {
          $ingredients = $pendingIngredients;
          $pendingIngredients = [];

          if (empty($ingredients)) {
            $unclassified[] = [
              'reason' => 'SHELL_WITHOUT_INGREDIENTS',
              'row' => $row,
            ];

            continue;
          }

          // Urutan racikan mengikuti bahan pertama
          // atau shell, mana yang Id-nya paling kecil.
          $sortIds = array_map(
            static fn($ingredient) =>
            (int) ($ingredient['Id'] ?? PHP_INT_MAX),
            $ingredients
          );

          $sortIds[] = (int) ($row['Id'] ?? PHP_INT_MAX);


          // seperate non-dtd
          $shellTypeNonDtd = in_array(
            $kode,
            $shellCodesNonDtd,
            true
          );

          if ($shellTypeNonDtd) {

            $items[] = [
              'type' => 'RACIKAN NON-DTD',
              'header' => $row,
              'ingredients' => $ingredients,
              // '_sort_id' => min($sortIds),
              '_sort_id' => (int) ($row['Id'] ?? PHP_INT_MAX),
            ];

            continue;
          } else {

            $items[] = [
              'type' => 'RACIKAN DTD',
              'header' => $row,
              'ingredients' => $ingredients,
              // '_sort_id' => min($sortIds),
              '_sort_id' => (int) ($row['Id'] ?? PHP_INT_MAX),
            ];

            continue;
          }
        }

        /*
     * Obat dengan aturan pakai adalah NC individual.
     */
        if ($hasAturanPakai) {
          $items[] = [
            'type' => 'NC',
            'row' => $row,
            '_sort_id' => (int) ($row['Id'] ?? PHP_INT_MAX),
          ];

          continue;
        }

        /*
       * Bahan tanpa aturan pakai menunggu shell
       * berikutnya.
       */
        $pendingIngredients[] = $row;
      }

      /*
       * Bahan yang tersisa tanpa shell tidak boleh
       * otomatis dijadikan racikan.
       */
      foreach ($pendingIngredients as $ingredient) {
        $unclassified[] = [
          'reason' => 'NO_ACTIVE_SHELL',
          'row' => $ingredient,
        ];
      }
    }

    /*
     * STEP 7.
     * Urutkan item final berdasarkan urutan sumber.
     */
    usort($items, static function ($a, $b) {
      return $a['_sort_id'] <=> $b['_sort_id'];
    });

    /*
     * STEP 8.
     * Generate identifier SETELAH normalisasi dan grouping.
     *
     * Index dimulai dari 0 untuk setiap kombinasi tanggal + resep.
     */
    $indexes = [];

    foreach ($items as &$item) {
      // $source = $item['type'] === 'RACIKAN'
      $source = str_contains($item['type'], 'RACIKAN')
        ? $item['header']
        : $item['row'];

      $prescriptionKey = implode('|', [
        (string) ($source['tanggal'] ?? ''),
        (string) ($source['rm'] ?? ''),
        (string) ($source['resep'] ?? ''),
      ]);

      $index = $indexes[$prescriptionKey] ?? 0;
      $indexes[$prescriptionKey] = $index + 1;

      $identifier = self::genIdentifierResepMedication(
        $source['tanggal'],
        $source['resep'],
        $index
      );

      $item['identifier_noresep'] =
        $identifier->identifier_noresep;

      $item['identifier_noresep_index'] =
        $identifier->identifier_noresep_index;

      if ($item['type'] === 'RACIKAN') {
        $item['header']['identifier_noresep'] =
          $identifier->identifier_noresep;

        $item['header']['identifier_noresep_index'] =
          $identifier->identifier_noresep_index;
      }

      unset($item['_sort_id']);
    }

    unset($item);

    return [
      'items' => $items,
      'unclassified' => $unclassified,
    ];
  }

  public static function generateIngredientNonDtd(
    // cangkangObt
    array $shellMed,
    // itemIngredient (ke-n)
    array $itemIngredient
  ) {

    print_r($shellMed);
    print_r($itemIngredient);

    $ingredientMedSimrs = SshtApiQueryMapping::getRefObatByLocalId($itemIngredient['id_local']);

    $ingredients = [
      'coding_code' => $ingredientMedSimrs['kfa_code'],
      'coding_display' => $ingredientMedSimrs['kfa_display'],
      // 'active' => , // bisa diset default..

      'kfa_bza' => $ingredientMedSimrs['kfa_bza'],

      'strength_nemunerator_value' => $itemIngredient['jumlah'],
      'strength_nemunerator_code' => '', // tambah kolom untuk simpan drug_form unit_code
      'strength_nemunerator_system' => '', // tambah kolom untuk simpan drug_form unit_system
      'strength_denominator_value' => $shellMed['jumlah'],
      'strength_denominator_code' => '', // tambah kolom untuk simpan drug_form unit_code untuk shellMed (cangkangObt)
      'strength_denominator_system' => '', // tambah kolom untuk simpan drug_form unit_system untuk shellMed (cangkangObt)
      'strength_denominator_unit' => '', // tambah kolom untuk simpan drug_from unit_display untuk shellMed (cangkangObt)
    ];

    return $ingredients;
  }

  // generateIngredientDtd (MedicationRequest atau MedicationDispense)
  public static function generateIngredientDtd(
    // flag medication (medication-request or medication-dispense)
    string $medicationResource,
    // cangkangObt
    array $shellMed,
    // itemIngredient (ke-n)
    array $itemIngredient
  ) {

    // print_r($medicationResource);
    // print_r($shellMed);
    // print_r($itemIngredient);

    if (
      !in_array(
        $medicationResource,
        ['medication-request', 'medication-dispense'],
        true
      )
    ) {
      throw new InvalidArgumentException(
        'Invalid medication resource: ' . $medicationResource
      );
    }

    $ingredientMedSimrs = SshtApiQueryMapping::getRefObatByLocalId($itemIngredient['id_local']);

    if (empty($ingredientMedSimrs)) {
      throw new RuntimeException(
        'Mapping obat tidak ditemukan: ' . $itemIngredient['id_local']
      );
    }

    $kfa_bza = json_decode($ingredientMedSimrs['kfa_bza'], true);
    // print_r($kfa_bza);

    // generateIngredientDtd (MedicationRequest)
    if ($medicationResource == 'medication-request') {
      // asumsi mapping dilakukan dengan benar,
      // obat racikan dtd biasanya komposisi tepat 1 active ingredient di bza
      $activeIngredient = (array) $kfa_bza[0];

      // print_r("masuk if generateIngredientDtd medication-request");
      // print_r($activeIngredient["kekuatan_zat_aktif"]);
      // exit;

      $sediaan = self::parseMedicationActiveIngredient(
        $activeIngredient["kekuatan_zat_aktif"],
        $ingredientMedSimrs["kfa_form"]
      );

      $kekuatanZatAktif = self::hitungKandunganMedicationIngredient(
        total: (float) $itemIngredient['jumlah'],
        sediaan: (float) $sediaan["numerator"]["value"],
        jumlahDispense: (float) $shellMed['jumlah']
      );

      if (!$kekuatanZatAktif["success"]) {
        return $kekuatanZatAktif;
      }

      $kekuatanZatAktifDenom = $sediaan['denominator'];

      $ingredients = [
        // 'coding_code' => $ingredientMedSimrs['kfa_code'],
        // 'coding_display' => $ingredientMedSimrs['kfa_display'],

        'coding_code' => $activeIngredient['kfa_code'],
        'coding_display' => $activeIngredient['zat_aktif'],

        // 'active' => , // bisa diset default..

        'kfa_bza' => $kfa_bza,

        'strength_nemunerator_value' => $kekuatanZatAktif["data"],
        'strength_nemunerator_code' => '', // tambah kolom untuk simpan drug_form unit_code
        'strength_nemunerator_system' => '', // tambah kolom untuk simpan drug_form unit_system
        // 'strength_denominator_value' => $shellMed['jumlah'],
        'strength_denominator_value' => $kekuatanZatAktifDenom["value"],
        'strength_denominator_code' => '', // tambah kolom untuk simpan drug_form unit_code untuk shellMed (cangkangObt)
        'strength_denominator_system' => '', // tambah kolom untuk simpan drug_form unit_system untuk shellMed (cangkangObt)
        'strength_denominator_unit' => '', // tambah kolom untuk simpan drug_from unit_display untuk shellMed (cangkangObt)
      ];

      // flag medication (medication-dispense)
    } elseif ($medicationResource == 'medication-dispense') {
      // asumsi mapping dilakukan dengan benar,
      // obat racikan dtd biasanya komposisi tepat 1 active ingredient di bza
      $activeIngredient = $kfa_bza[0];

      // $sediaan = self::parseMedicationActiveIngredient($activeIngredient["kekuatan_zat_aktif"], $ingredientMedSimrs["kfa_form"]);

      // $kekuatanZatAktif = self::hitungKandunganMedicationIngredient(
      //   total: (float) $itemIngredient['jumlah'],
      //   sediaan: (float) $sediaan["nemunerator"]["value"],
      //   jumlahDispense: (float) $shellMed['jumlah']
      // );

      // if(!$kekuatanZatAktif["success"]){
      //   return $kekuatanZatAktif;
      // }

      $ingredients = [
        'coding_code' => $ingredientMedSimrs['kfa_code'],
        'coding_display' => $ingredientMedSimrs['kfa_display'],
        // 'active' => , // bisa diset default..

        'kfa_bza' => $kfa_bza,

        'strength_nemunerator_value' => $itemIngredient["jumlah"],
        'strength_nemunerator_code' => '', // tambah kolom untuk simpan drug_form unit_code
        'strength_nemunerator_system' => '', // tambah kolom untuk simpan drug_form unit_system
        'strength_denominator_value' => $shellMed['jumlah'],
        'strength_denominator_code' => '', // tambah kolom untuk simpan drug_form unit_code untuk shellMed (cangkangObt)
        'strength_denominator_system' => '', // tambah kolom untuk simpan drug_form unit_system untuk shellMed (cangkangObt)
        'strength_denominator_unit' => '', // tambah kolom untuk simpan drug_from unit_display untuk shellMed (cangkangObt)
      ];
      // else {
      //   return 
      // }
    }

    return $ingredients;
  }

  public static function generateIngredientNC(
    // cangkangObt
    $shellMed = false,
    // itemIngredient (ke-n)
    $itemIngredient
  ) {

    // print_r($shellMed);
    // print_r($itemIngredient);

    $ingredientMedSimrs = SshtApiQueryMapping::getRefObatByLocalId($itemIngredient['id_local']);

    if (empty($ingredientMedSimrs)) {
      return;
    }

    print_r("\n");
    print_r($ingredientMedSimrs);

    $bza = json_decode($ingredientMedSimrs['kfa_bza'] ?? [], true);
    $kfa_form = json_decode($ingredientMedSimrs['kfa_form'] ?? [], true);

    if (json_last_error() !== JSON_ERROR_NONE || !is_array($bza)) {
      return;
    }

    $ingredientBza = [];

    foreach ($bza as $row) {

      $genIngredientKfa = self::parseMedicationActiveIngredient(
        text: $row['kekuatan_zat_aktif'],
        kfa_form_name: $kfa_form['name'],
      );

      $ingredientBza[] = [
        'coding_code' => $ingredientMedSimrs['kfa_code'],
        'coding_display' => $ingredientMedSimrs['kfa_display'],
        // 'active' => , // bisa diset default..

        // 'kfa_bza' => $ingredientMedSimrs['kfa_bza'],

        'strength_nemunerator_value' => $genIngredientKfa['numerator']['value'],
        'strength_nemunerator_code' => $genIngredientKfa['numerator']['code'],
        'strength_nemunerator_system' => $genIngredientKfa['numerator']['system'],
        'strength_denominator_value' => $genIngredientKfa['denominator']->value,
        'strength_denominator_code' => $genIngredientKfa['denominator']->code, // tambah kolom untuk simpan drug_form unit_code untuk shellMed (cangkangObt)
        'strength_denominator_system' => $genIngredientKfa['denominator']->system, // tambah kolom untuk simpan drug_form unit_system untuk shellMed (cangkangObt)
        'strength_denominator_unit' => $genIngredientKfa['denominator']->unit ?? $genIngredientKfa['denominator']->code, // tambah kolom untuk simpan drug_from unit_display untuk shellMed (cangkangObt)
      ];
    }

    $ingredients = $ingredientBza;

    return $ingredients;
  }

  public static function denominatorActiveIngredient($kfa_form_name, $qty_unit = 1): object
  {
    $form = strtolower($kfa_form_name);

    // if ($kfa_form_name == "Tablet") {
    if (str_contains($form, 'tablet')) {
      return (object) [
        "value" => $qty_unit,
        "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
        "code" => "TAB"
        // "value" => 1,
        // "unit" => "Tablet - unit of product usage",
        // "system" => "http://snomed.info/sct",
        // "code" => "428673006"
      ];
      // } elseif ($kfa_form_name == "Kapsul") {
    } elseif (str_contains($form, 'kapsul')) {
      return (object) [
        "value" => (float) $qty_unit,
        "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
        "code" => "CAP"
      ];
    } elseif ($kfa_form_name == "Serbuk Oral") {
      return (object) [
        "value" => (float) $qty_unit,
        "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
        "code" => "POWD"
      ];
    } elseif (
      $kfa_form_name == "Serbuk Injeksi" ||
      $kfa_form_name == "Suspensi Injeksi" ||
      $kfa_form_name == "Larutan Injeksi"
    ) {
      return (object) [
        "value" => 1,
        "unit" => "Vial - unit of presentation",
        "system" => "http://snomed.info/sct",
        "code" => "733026001"
      ];
      // semprot
    } elseif ($kfa_form_name == "Supositoria") {
      return (object) [
        "value" => 1,
        "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
        "code" => "SUPP"
      ];
      // } elseif ($kfa_form_name == "Gel" || $kfa_form_name == "Salep") {
    } elseif ($kfa_form_name == "Gel" || str_contains($form, 'salep')) {
      return (object) [
        "value" => 1,
        "unit" => "Tube - unit of presentation",
        "system" => "http://snomed.info/sct",
        "code" => "733024003"
      ];
    } elseif ($kfa_form_name == "Infus") {
      return (object) [
        "value" => 1,
        "unit" => "Bottle - unit of presentation",
        "system" => "http://snomed.info/sct",
        "code" => "732986007"
        // deprecated - inactive
        // "unit" => "Bottle - unit of product usage",
        // "system" => "http://snomed.info/sct",
        // "code" => "419672006"
      ];
      // } elseif (
      //     $kfa_form_name == "Larutan Injeksi" ||
      //     $kfa_form_name == "Tetes Mata" ||
      //     $kfa_form_name == "Krim" ||
      //     $kfa_form_name == "Suspensi" ||
      //     $kfa_form_name == "Sirup"
      // ) {
      //     $res = explode(' ', $qty_unit);
      //
      //     Log::info('res-denominator-last', ["res" => $res]);
      //
      //     return (object) [
      //         "value" => (float) $res[0],
      //         // "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
      //         "system" => "http://unitsofmeasure.org",
      //         "code" => $res[1]
      //     ];
      // } else {
      //     return (object) [
      //         "error" => "denominator param kfa_form_name must be the valid on kfa_form"
      //     ];
      // }
      // 2026-10-06 10:01 - asumsi naive semua data active ingridient sudah disediakan api kfa kemkes
    } else {
      $res = explode(' ', $qty_unit);

      // Log::info('res-denominator-last', ["res" => $res]);

      return (object) [
        "value" => (float) $res[0],
        // "system" => "http://terminology.hl7.org/CodeSystem/v3-orderableDrugForm",
        "system" => "http://unitsofmeasure.org",
        "code" => $res[1]
      ];
    }
  }


  // Medication active ingridient
  public static function parseMedicationActiveIngredient(string $text, string $kfa_form_name): ?array
  {
    // Hilangkan spasi berlebih
    $text = trim(preg_replace('/\s+/', ' ', $text));

    // Log::info('parseMedicationActiveIngredient', ["text" => $text]);

    // Cocokkan pola umum UCUM
    // if (preg_match('/([\d\.]+)\s*([A-Za-zµ\/]+)\/([\d\.]+)\s*([A-Za-zµ\/]+)/', $text, $m)) {
    // 2026-10-07 02:25 - allow UCUM [IU]/[iU] etc..
    if (preg_match('/^\s*([\d.,]+)\s*([^\s\/]+)\s*\/\s*([\d.,]+)\s*([^\s\/]+)\s*$/u', trim($text), $m)) {

      // Log::info('preg_match_1', [
      //   "result" => $m
      // ]);

      return [
        "numerator" => [
          "value" => (float)$m[1],
          "system" => "http://unitsofmeasure.org",
          "code"  => $m[2]
        ],
        "denominator" => [
          "value" => (float)$m[3],
          "system" => "http://unitsofmeasure.org",
          "code"  => $m[4]
        ]
        // "denominator" => self::denominatorActiveIngredient(
        //     kfa_form_name: $kfa_form_name,
        //     qty_unit: (string) $m[3] . ' ' . $m[4]
        // ),

      ];
    }

    // fallback: kalau cuma satuan aja (misal "250 mg")
    // if (preg_match('/([\d\.]+)\s*([A-Za-zµ\/]+)/', $text, $m)) {
    // if (preg_match('/([\d\.]+)\s*([A-Za-z%\/]+)/', $text, $m)) {
    if (preg_match('/([\d.,]+)\s*([^\s\/]+)/u', $text, $m)) {

      // Log::info('preg_match_2', [
      //   "result" => $m
      // ]);

      return [
        "numerator" => [
          "value" => (float)$m[1],
          "system" => "http://unitsofmeasure.org",
          "code"  => $m[2]
        ],
        // "denominator" => [
        //     "value" => 1,
        //     "system" => "http://unitsofmeasure.org",
        //     "code"  => "1"
        // ]
        "denominator" => (array) self::denominatorActiveIngredient(
          kfa_form_name: $kfa_form_name,
          qty_unit: 1
        ),
      ];
    }

    // gagal parse
    return null;
  }

  public static function hitungKandunganMedicationIngredient(
    // local frm_ralan.jumlah
    float $total = 0.0,
    // kekuatan sediaan di kfa
    float $sediaan = 0.0,
    // jumal mau dibuat berapa unit
    float $jumlahDispense = 0.0
  ): array {

    // print_r("\nmasuk ke hitungKandunganMedicationIngredient");
    // print_r("\ntotal: " . $total);
    // print_r("\nsediaan: " . $sediaan);
    // print_r("\njumlahDispense: " . $jumlahDispense);
    //
    // var_dump($total);
    // var_dump($sediaan);
    // var_dump($jumlahDispense);

    if ($jumlahDispense == 0.0) {
      return [
        "success" => false,
        "error" => true,
        "message" => "error param bernilai 0 atau tidak valid"
      ];
    }

    // print_r("\n");
    //
    // var_dump((float) $total);
    // var_dump((float) $sediaan);
    // var_dump((float) $jumlahDispense);

    $kekuatan_satu_shell = ($total * $sediaan) / $jumlahDispense;

    return [
      "success" => true,
      "error" => false,
      "data" => $kekuatan_satu_shell,
      "message" => "ok"
    ];
  }
}
