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
    $shellCodes = [
      'LD0604',
      'LD0605',
      'LD0614',
      'Z2005515',
      'Z2020175',
      'Z2021089',
      'LD0616',
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
     * Normalisasi mutasi berdasarkan:
     * tanggal + RM + resep + kode_brg.
     *
     * Contoh:
     * LD0605 +10
     * LD0605 -10
     * Hasil = 0, dibuang.
     *
     * Obat biasa juga dinormalisasi dengan aturan sama.
     */
    $totals = [];

    foreach ($rows as $row) {
      $kode = trim((string) ($row['kode_brg'] ?? ''));

      $key = implode('|', [
        (string) ($row['tanggal'] ?? ''),
        (string) ($row['rm'] ?? ''),
        (string) ($row['resep'] ?? ''),
        $kode,
      ]);

      if (!isset($totals[$key])) {
        $totals[$key] = [
          'row' => $row,
          'jumlah' => 0.0,
        ];
      }

      $totals[$key]['jumlah'] +=
        (float) ($row['jumlah'] ?? 0);
    }

    $normalized = [];

    foreach ($totals as $entry) {
      $jumlah = $entry['jumlah'];

      // Nol atau negatif tidak dikirim.
      if ($jumlah <= 0) {
        continue;
      }

      $row = $entry['row'];
      $row['jumlah'] = (string) $jumlah;

      $normalized[] = $row;
    }

    usort($normalized, static function ($a, $b) {
      return (int) ($a['Id'] ?? 0)
        <=> (int) ($b['Id'] ?? 0);
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
    $unclassified = [];

    foreach ($prescriptions as $prescriptionRows) {
      $shells = [];
      $ncRows = [];
      $ingredientRows = [];

      /*
         * STEP 4.
         * Klasifikasi setelah normalisasi.
         */
      foreach ($prescriptionRows as $row) {
        $kode = trim((string) ($row['kode_brg'] ?? ''));

        $isShell = in_array(
          $kode,
          $shellCodes,
          true
        );

        if ($isShell) {
          $shells[] = $row;
          continue;
        }

        $hasAturanPakai =
          trim((string) ($row['kali'] ?? '')) !== ''
          || trim((string) ($row['hari'] ?? '')) !== '';

        if ($hasAturanPakai) {
          $ncRows[] = $row;
        } else {
          $ingredientRows[] = $row;
        }
      }

      /*
         * STEP 5.
         * Bentuk item racikan.
         *
         * Satu cangkang aktif:
         * semua obat tanpa aturan pakai menjadi komposisi.
         *
         * Lebih dari satu cangkang aktif:
         * jangan menebak hubungan komposisi.
         */
      if (count($shells) === 1) {
        $header = $shells[0];

        $items[] = [
          'type' => 'RACIKAN',
          'header' => $header,
          'ingredients' => $ingredientRows,
          '_sort_id' => (int) ($header['Id'] ?? PHP_INT_MAX),
          // '_sort_id' => min(
          //   array_merge(
          //     [(int) ($header['Id'] ?? PHP_INT_MAX)],
          //     array_map(
          //       static fn($row) =>
          //       (int) ($row['Id'] ?? PHP_INT_MAX),
          //       $ingredientRows
          //     )
          //   )
          // ),
        ];
      } elseif (count($shells) > 1) {
        foreach ($shells as $shell) {
          $unclassified[] = [
            'reason' => 'MULTIPLE_ACTIVE_SHELLS',
            'row' => $shell,
          ];
        }

        foreach ($ingredientRows as $row) {
          $unclassified[] = [
            'reason' => 'AMBIGUOUS_INGREDIENT',
            'row' => $row,
          ];
        }
      } else {
        foreach ($ingredientRows as $row) {
          $unclassified[] = [
            'reason' => 'NO_ACTIVE_SHELL',
            'row' => $row,
          ];
        }
      }

      /*
         * STEP 6.
         * NC selalu item individual.
         */
      foreach ($ncRows as $row) {
        $items[] = [
          'type' => 'NC',
          'row' => $row,
          '_sort_id' => (int) ($row['Id'] ?? PHP_INT_MAX),
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
      $source = $item['type'] === 'RACIKAN'
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

  // /* 
  //  * Grouping Medication/obat dari query
  //  *
  //  * untuk membagi identifier dan kelompok/jenis tipe obat
  //  */
  // public static function groupMedicationRows(array $rows): array
  // {
  //   // Mapping kode cangkang kode obat racikan.
  //   // Case: RSPRin
  //   // nama, kode_brg, Id
  //   // 'RACIKAN PUYER', 'LD0604', '27239',
  //   // 'RACIKAN CAPSUL', 'LD0605', '27240',
  //   // 'RACIKAN DRANK', 'LD0614', '27241',
  //   // 'RACIKAN IN POT', 'Z2005515', '28930',
  //   // 'RACIKAN POT KOSMETIK', 'Z2020175', '35003',
  //   // 'RACIKAN POT KOSMETIK', 'Z2020175', '35003',
  //   // 'RACIKAN BOTOL', 'Z2021089', '35528',
  //   // 'RACIKAN SALEP/KRIM', 'LD0616', '35529'
  //   $shellCodes = [
  //     'LD0604',
  //     'LD0605',
  //     'LD0614',
  //     'Z2005515',
  //     'Z2020175',
  //     'Z2021089',
  //     'LD0616',
  //   ];
  //
  //   $items = [];
  //   $unclassified = [];
  //   $currentRacikan = null;
  //
  //   // 0.0 normalisasi by kode_brg & jumlah 
  //   // if ada yang saling mengurangi = 0 -> pop array
  //   foreach ($rows as $row) {
  //     $kodeBarang = trim((string) ($row['kode_brg'] ?? ''));
  //
  //     $selectedCangkang = in_array(
  //       $kodeBarang,
  //       $shellCodes,
  //       true
  //     );
  //
  //     // normalisasi data misal ada id cangkang = 10 lalu kemudian ada lagi id cangkang yang sama namun dengan -10
  //     // nanti di pop array..
  //
  //     // kalau kelebihan lalu hasilnya nanti masih positive tetep pake dengan jumlah yang telah di kurangkan 
  //
  //     // kalau hasilnya negative skip
  //
  //
  //   }
  //
  //   // 0.1 generate identifier tanggal-noresep-index
  //   // dengan case exist frm_ralan.kali & frm_ralan.hari & frm_ralan.sediaan & frm_ralan.waktu 
  //   // maka dibuatkan identifier key id nya..
  //
  //   // 0.2 grouping medication by type NC / racikan
  //   // case not in kode_brg = racikan() ['',''] and 
  //   // isset($arr['tanggal-noresep-index']) == true 
  //   //     then NC -> $arrNC[] => ['tanggal-noresep-index' => '', blabla..]
  //
  //   // 0.3 sort urutan record ke desc
  //   // raw-racikan => $arr
  //   // sort by tanggal-noresep-index desc 
  //
  //   // 0.4 generate ingredients racikan use key ($arr['tanggal-noresep-index'])
  //   // and sort ke asc seperti semula by key $arr['tanggal-noresep-index']
  //   // racikan => $arrRacik[] => generateIngredientRacik($arr)
  //
  //   // 0.5 merge gabung 2 array tersebut $arrRacik[] & $arrNC[] by key ($id = 'tanggal-noresep-index') asc
  //
  //   // Query sumber harus sudah ORDER BY fr.Id ASC.
  //   foreach ($rows as $row) {
  //     $kodeBarang = trim((string) ($row['kode_brg'] ?? ''));
  //
  //     $isHeader = in_array(
  //       $kodeBarang,
  //       $shellCodes,
  //       true
  //     );
  //
  //     // Sesuaikan definisi ini dengan aturan pakai
  //     // yang benar-benar dipakai oleh SIMRS.
  //     $hasAturanPakai =
  //       trim((string) ($row['kali'] ?? '')) !== ''
  //       || trim((string) ($row['hari'] ?? '')) !== '';
  //
  //     // 1. Header racikan selalu menjadi batas kelompok.
  //     if ($isHeader) {
  //       if ($currentRacikan !== null) {
  //         $items[] = $currentRacikan;
  //       }
  //
  //       $currentRacikan = [
  //         'type' => 'RACIKAN',
  //         'header' => $row,
  //         'ingredients' => [],
  //       ];
  //
  //       continue;
  //     }
  //
  //     // 2. Baris tanpa aturan pakai di dalam kelompok
  //     // aktif menjadi kandidat komposisi racikan.
  //     if ($currentRacikan !== null && !$hasAturanPakai) {
  //       $currentRacikan['ingredients'][] = $row;
  //       continue;
  //     }
  //
  //     // 3. Baris beraturan untuk mengakhiri kelompok
  //     // sebelumnya, kemudian diproses sebagai NC.
  //     if ($currentRacikan !== null) {
  //       $items[] = $currentRacikan;
  //       $currentRacikan = null;
  //     }
  //
  //     if ($hasAturanPakai) {
  //       $items[] = [
  //         'type' => 'NC',
  //         'row' => $row,
  //       ];
  //     } else {
  //       // default case jenis item jika tidak ada
  //       // header aktif dan aturan pakai kosong.
  //       $unclassified[] = $row;
  //     }
  //   }
  //
  //   // 4. Flush kelompok terakhir, meskipun tidak ada NC.
  //   if ($currentRacikan !== null) {
  //     $items[] = $currentRacikan;
  //   }
  //
  //   // 5. sort urutan ke asc
  //
  //   return [
  //     'items' => $items,
  //     'unclassified' => $unclassified,
  //   ];
  // }
}
