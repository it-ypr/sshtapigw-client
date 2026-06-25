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
    if (empty($rtfContent)) return ["", ""];

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
      $result['systolic'] = (int)$m[2];
      $result['diastolic'] = (int)$m[3];
    }

    // Heart rate - Nadi
    if (preg_match('/^\s*(N|Nadi)\s*[:;]\s*(\d+)/im', $text, $m)) {
      $result['heart_rate'] = (int)$m[2];
    }

    // Temperature - Suhu (S)
    if (preg_match('/^\s*(S|Suhu)\s*[:;]\s*([\d.]+)/im', $text, $m)) {
      $result['body_temp'] = (float)$m[2];
    }

    // Respiration Rate / Pernafasan (RR)
    if (preg_match('/RR\s*[:;]\s*(\d+)/i', $text, $m)) {
      $result['respiratory_rate'] = (int) $m[1];
    }

    // GDS
    if (preg_match('/^\s*GDS\s*[:;]\s*(\d+)/im', $text, $m)) {
      $result['gds'] = (int)$m[1];
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

  public static function genIdentifierResepMedication($tgl_param, $resep_param, $index = 1): object
  {
    if ($index == 0) {
      $codedate = Carbon::parse($tgl_param)->format('Ymd');
      $idnresep = (string)$codedate . '-' . (string) $resep_param;

      return (object) [
        "identifier_resep" => $idnresep,
        "identifier_resep_index" => $idnresep . "-" . "1"
      ];
    }

    $codedate = (string) Carbon::parse($tgl_param)->format('Ymd');
    $idnresep = $codedate . '-' . (string) $resep_param;

    return (object) [
      "identifier_resep" => $idnresep,
      "identifier_resep_index" => $idnresep . "-" . (string) $index
    ];
  }
}
