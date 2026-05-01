<?php

namespace common\services\SshtApiGwClient\util;

use Jstewmc\Rtf\Document;
use Yii;

class SshtApiUtil
{

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
}
