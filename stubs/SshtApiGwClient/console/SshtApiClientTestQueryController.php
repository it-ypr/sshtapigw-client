<?php

namespace common\services\SshtApiGwClient\console;

use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;
use yii\console\Controller;
use yii\db\Query;
use yii\helpers\Json;
use Exception;
use GuzzleHttp\Client;
use Yii;
use yii\db\Expression;

/**
 * SshtApiClientTestController
 *
 * SshtApiClientTestController iki untuk test query di SshtApiQueryMapping
 */
class SshtApiClientTestQueryController extends Controller
{
  /**
   * Run: php yii ssht-test-query/test-hello
   */
  public function actionTestHello()
  {
    $config = SshtApiBase::getConfig();
    echo "Hi.. \n";
    echo "config load: " . json_encode($config) . "\n";
    // return ExitCode::OK;
  }

  /**
   * Run: php yii ssht-test-query/encounter-ranap-test 2025-05-01
   */
  public static function actionEncounterRanapTest($tgl_param)
  {
    // $tgl_param = tanggal masuk pasien
    $dataEncounter = SshtApiQueryMapping::queryEncounterRanapSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan Data Ranap: " . count($dataEncounter) . " data.\n";
    // print_r($dataEncounter[0]);
    print_r($dataEncounter);
  }

  /**
   * Run: php yii ssht-test-query/encounter-ralan-test 2025-05-01 rm
   */
  public static function actionEncounterRalanTest($tgl_param)
  {
    $dataEncounter = SshtApiQueryMapping::queryEncounterRalanSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";
    print_r($dataEncounter);
    // print_r($dataEncounter[0]);
  }


  /**
   * Run: php yii ssht-test-query/encounter-ugd-test 2025-05-01
   */
  public static function actionEncounterUgdTest($tgl_param)
  {
    $dataEncounter = SshtApiQueryMapping::queryEncounterUgdSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";
    print_r($dataEncounter);
    // print_r($dataEncounter[0]);
    // print_r($dataEncounter[1]);
  }

  // public static function actionRadioQuery() {
  //   
  // }

  /**
   * Run: php yii ssht-test-query/observation-vital-ranap xxxx 2025-05-01 xxxxx
   */
  public static function actionObservationVitalRanap($noregis, $tgl_param, $rm)
  {
    // $tgl_param = tanggal masuk pasien
    $dataEncounter = SshtApiQueryMapping::queryObservationRanap($noregis, $rm);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    print_r($dataEncounter);
  }

  /**
   * Run: php yii ssht-test-query/lab-ralan-test 2025-05-01 rm
   */
  public static function actionLabRalanTest(string $tgl_param, string $rm_param)
  {
    // $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm_param);
    $dataLabRalan = SshtApiQueryMapping::queryLabUgd($tgl_param, $rm_param);

    if (empty($dataLabRalan)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataLabRalan) . " data.\n";
    print_r($dataLabRalan);
    // print_r($dataLabRalan[0]);
  }

  /**
   * Test output data observation lab ralan
   * Example command: php yii ssht-test-query/obs-lab-ralan-test 2025-09-29 320904 58410-2
   */
  public static function actionObsLabRalanTest(
    string $tgl_param,
    string $rm_param,
    string $loinc_order
  ) {
    $dataObsLabRalan = SshtApiQueryMapping::getObservationLabLocalRalan(
      $tgl_param,
      $rm_param,
      $loinc_order
    );

    if (empty($dataObsLabRalan)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
    }

    echo "Ditemukan " . count($dataObsLabRalan) . " data.\n";
    print_r($dataObsLabRalan);
  }

  /**
   * Run: php yii ssht-test-query/lab-ugd-test 2025-05-01
   */
  public static function actionLabUgdTest(string $tgl_param, string $rm)
  {
    // $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm_param);
    $dataLabUgd = SshtApiQueryMapping::queryLabUgd($tgl_param, $rm);

    if (empty($dataLabUgd)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataLabUgd) . " data.\n";
    print_r($dataLabUgd);
    // print_r($dataLabRalan[0]);
  }

  /**
   * php yii ssht-test-query/procedure-general-test 2025-09-27 055129
   */
  public function actionProcedureGeneralTest(string $tanggal, string $rm)
  {
    $simrsobs = SshtApiQueryMapping::queryProcedureGeneral($tanggal, $rm);
    if ($simrsobs) {
      print_r($simrsobs);
    } else {
      echo "\ntidak ditemukan data procedure.\n";
    }
  }

  /**
   * php yii ssht-test-query/procedure-general-ranap 2025-09-27 055129
   */
  public function actionProcedureGeneralRanap(string $tanggal, string $rm)
  {
    $simrsobs = SshtApiQueryMapping::queryProcedureGeneralRanap($tanggal, $rm);
    if ($simrsobs) {
      print_r($simrsobs);
    } else {
      echo "\ntidak ditemukan data procedure.\n";
    }
  }

  /**
   * php yii ssht-test-query/send-observation-ralan-test 2026-05-01 rm
   */
  public function actionSendObservationRalanTest($tanggal, $rm)
  {
    $simrsobs = SshtApiQueryMapping::queryObservationRalan($tanggal, $rm);
    if ($simrsobs) {
      print_r($simrsobs);
    } else {
      echo "\ntidak ditemukan data observasi KO.\n";
    }
  }

  /**
   * Run: php yii ssht-test-query/service-request-radio-ranap 2025-05-01
   */
  public static function actionServiceRequestRadioRanap($tgl_param)
  {
    $class = "IMP";
    // 1. Ambil data encounter dari DB Lokal
    $encounters = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'practition_idIHS',
        'inprogress_start',
        'class',
        'noregis',
      ])
      ->from('ssht_encounter')
      ->where([
        'CAST(inprogress_start AS DATE)' => $tgl_param,
      ])
      ->andWhere([
        'class' => $class,
      ])
      ->all(Yii::$app->sshtAPIdb);


    if (empty($encounters)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($encounters) . " data.\n";
    print_r($encounters);
    // print_r($dataEncounter[0]);
    // print_r($dataEncounter[1]);

    foreach ($encounters as $enc) {
      $rm = $enc['subject_rm'];
      $noregis = $enc['noregis'];

      $dataServiceRequest = SshtApiQueryMapping::queryServiceRequestSimrsRadio(
        encounter_class: $class,
        rm: $rm,
        tgl_param: $tgl_param,
        noregis: $noregis
      );

      if (!$dataServiceRequest) {
        echo "[-] SKIP: RM {$rm} tydac ada order radiologi\n";

        continue;
      }


      // 3. Guard duplicate ServiceRequest RANAP
      $hourStart = date(
        'Y-m-d H:00:00',
        strtotime($enc['inprogress_start'])
      );

      $hourEnd = date(
        'Y-m-d H:00:00',
        strtotime(
          $enc['inprogress_start'] . ' +1 hour'
        )
      );

      $duplicateServiceRequestRadioUgd =
        Yii::$app->sshtAPIdb->createCommand("
            SELECT ssht_servicerequest.servicerequest_idIHS
            FROM ssht_servicerequest
            LEFT JOIN ssht_encounter on ssht_servicerequest.encounter_idIHS = ssht_encounter.idIHS 
            WHERE patient_idIHS = :subject_idIHS
              AND ssht_servicerequest.encounter_idIHS = :encounter_idIHS
              AND ssht_servicerequest.petugas_idIHS = :petugas_idIHS
              AND ssht_servicerequest.dokter_request_idIHS = :dokter_request_idIHS
              AND ssht_servicerequest.code = :loinc_code
              AND ssht_servicerequest.category_display = 'Imaging'
              AND ssht_servicerequest.acsn = :acsn
              AND ssht_servicerequest.date >= :hour_start
              AND ssht_servicerequest.date < :hour_end
              AND ssht_encounter.class = 'IMP'
            LIMIT 1
          ")
        ->bindValues([
          ':subject_idIHS' => $enc['subject_idIHS'] ?? null,

          ':encounter_idIHS' => $enc['idIHS'],

          ':petugas_idIHS' => $dataServiceRequest['petugas_ihs'] ?? null,

          ':dokter_request_idIHS' => $enc['practition_idIHS'] ?? null,

          ':loinc_code' => $dataServiceRequest['loinc'] ?? null,

          ':acsn' => $dataServiceRequest['noradio'],

          ':hour_start' => $hourStart,

          ':hour_end' => $hourEnd,
        ])
        ->queryScalar();

      if ($duplicateServiceRequestRadioUgd) {
        echo " SKIPPED " . "(DUPLICATE ServiceRequest Radio - RANAP)";

        echo " [IHS: {$duplicateServiceRequestRadioUgd}]";

        echo " [{$hourStart}]\n";
        continue;
      }

      // 4. Persiapkan payload
      $payload = [
        'noradio' => $dataServiceRequest['noradio'],
        'tagging' => $dataServiceRequest['kode'],
        'loinc' => $dataServiceRequest['loinc'] ?? '',
        'category' => 'radio',
        'reason' => $dataServiceRequest['indikasi']
          ?? 'Permintaan Radiologi',
        'encounter_idIHS' => $enc['idIHS'],
        'dokter' => trim($dataServiceRequest['dkirim']),
        'rm' => $rm,
        'petugas_idIHS' => $dataServiceRequest['petugas_ihs'] ?? '',
        'petugas_nama' => trim($dataServiceRequest['nm_user'] ?? ''),
      ];

      echo "\n";
      echo Json::encode($payload);
      echo "\n";

      // endforeach
    }
  }

  /**
   * php yii ssht-test-query/test-query-diagnostic-report-lab 2026-05-01 rm
   */
  public function actionTestQueryDiagnosticReportLab($tgl_param, $encounterClass)
  {
    $query = (new Query())
      ->select([
        'ssr.id',
        'ssr.date',
        'ssr.servicerequest_idIHS',
        'sse.class',
        'sse.idIHS',
        'ssr.encounter_idIHS',
        'ssr.category_code',
        'ssr.category_display',
        'ssr.code',
        'ssr.display',
        'ssr.perihal',
        'ssr.rm',
        'ssr.dok',
        'ssr.dokter_request_idIHS',
        'ssr.patient_idIHS',
        'ssr.petugas_idIHS',
        'ssr.petugas_nama',
      ])
      ->from(['ssr' => 'ssht_servicerequest'])
      ->leftJoin(
        ['sse' => 'ssht_encounter'],
        'ssr.encounter_idIHS = sse.idIHS'
      )
      ->where([
        'sse.class' => $encounterClass,
        'ssr.category_display' => 'Laboratory procedure',
      ])
      ->andWhere(
        ['=', new Expression('CAST(ssr.date AS DATE)'), $tgl_param]
      );

    echo $query->createCommand(Yii::$app->sshtAPIdb)->getRawSql();
    echo PHP_EOL;

    $rows = $query->all(Yii::$app->sshtAPIdb);

    print_r($rows);
  }

  /**
   * Run:
   * php yii ssht-test-query/medication-ralan 2026-10-01 311957
   */
  public static function actionMedicationRalan(
    string $tgl_param,
    string $rm
  ) {
    $dataObat = SshtApiQueryMapping::queryMedicationRalan(
      $tgl_param,
      $rm
    );

    if (empty($dataObat)) {
      echo "Data tidak ditemukan untuk tanggal {$tgl_param}\n";
      return;
    }

    echo "\n========== DATA MENTAH ==========\n";
    echo "Jumlah: " . count($dataObat) . "\n";
    print_r($dataObat);

    $grouped = SshtApiUtil::groupMedicationRows($dataObat);

    echo "\n========== HASIL GROUPING ==========\n";
    echo "Jumlah MedicationRequest: "
      . count($grouped['items']) . "\n";

    foreach ($grouped['items'] as $i => $item) {
      echo "\n--- ITEM " . ($i + 1) . " ---\n";
      echo "Type: " . $item['type'] . "\n";
      echo "Identifier: "
        . $item['identifier_noresep_index'] . "\n";

      if ($item['type'] === 'NC') {
        echo "Nama: "
          . ($item['row']['nama'] ?? '-') . "\n";

        echo "Kode: "
          . ($item['row']['kode_brg'] ?? '-') . "\n";

        echo "Jumlah: "
          . ($item['row']['jumlah'] ?? '-') . "\n";
      } else {
        echo "Header: "
          . ($item['header']['nama'] ?? '-') . "\n";

        echo "Kode: "
          . ($item['header']['kode_brg'] ?? '-') . "\n";

        echo "Jumlah: "
          . ($item['header']['jumlah'] ?? '-') . "\n";

        echo "Komposisi: "
          . count($item['ingredients']) . " bahan\n";

        foreach ($item['ingredients'] as $ingredient) {
          echo "  - "
            . ($ingredient['nama'] ?? '-') . " | "
            . ($ingredient['kode_brg'] ?? '-') . " | "
            . ($ingredient['jumlah'] ?? '-') . "\n";
        }
      }
    }

    echo "\n========== UNCLASSIFIED ==========\n";
    print_r($grouped['unclassified']);

    echo "\n========== Print Grouped ==========\n";
    print_r($grouped);



    echo "\n========== Payload ==========\n";

    if (empty($grouped['items'])) {
      print_r("empty data..");
      return;
    }

    // INISIALISASI DI LUAR FOREACH
    $payloadMedication = [];
    $payloadMedicationDispense = [];

    foreach ($grouped['items'] as $key => $obt) {

      $medType = $obt['type'];

      // Penampung ingredient per item obat
      $ingredientRequest = [];
      $ingredientDispense = [];

      // ==========================================
      // RACIKAN NON-DTD
      // ==========================================
      if ($medType === 'RACIKAN NON-DTD') {

        foreach ($obt['ingredients'] as $ingKey => $ingSource) {

          $ingredient = SshtApiUtil::generateIngredientNonDtd(
            shellMed: $obt['header'],
            itemIngredient: $ingSource
          );

          $ingredientRequest[] = $ingredient;
          $ingredientDispense[] = $ingredient;
        }

        $shellMed = $obt['header'];

        $medicationType = 'EP';
      }

      // ==========================================
      // RACIKAN DTD
      // ==========================================
      elseif ($medType === 'RACIKAN DTD') {

        foreach ($obt['ingredients'] as $ingKey => $ingSource) {

          // MedicationRequest
          $genIngredientDtdRequest =
            SshtApiUtil::generateIngredientDtd(
              medicationResource: 'medication-request',
              shellMed: $obt['header'],
              itemIngredient: $ingSource
            );

          // MedicationDispense
          $genIngredientDtdDispense =
            SshtApiUtil::generateIngredientDtd(
              medicationResource: 'medication-dispense',
              shellMed: $obt['header'],
              itemIngredient: $ingSource
            );

          // Simpan SEMUA ingredient
          if ($genIngredientDtdRequest !== null) {
            $ingredientRequest[] = $genIngredientDtdRequest;
          }

          if ($genIngredientDtdDispense !== null) {
            $ingredientDispense[] = $genIngredientDtdDispense;
          }
        }

        $shellMed = $obt['header'];

        $medicationType = 'SD';
      }

      // ==========================================
      // NON RACIKAN (NC)
      // ==========================================
      elseif ($medType === 'NC') {

        $shellMed = $obt['row'];

        $ingredient = SshtApiUtil::generateIngredientNC(
          shellMed: false,
          itemIngredient: $obt['row']
        );

        // generateIngredientNC menghasilkan array
        // berisi ingredient berdasarkan BZA.
        $ingredientRequest = $ingredient ?? [];
        $ingredientDispense = $ingredient ?? [];

        $medicationType = 'NC';
      } else {

        echo "Unclassified medication type: {$medType}\n";
        continue;
      }

      // ==========================================
      // BASE PAYLOAD
      // ==========================================

      $basePayload = [
        'encounter_idIHS' => '',

        'patient_idIHS' => '',
        'patient_nama' => '',
        'rm' => '',
        'dok' => '',
        'practition_idIHS' => '',
        'practition_nama' => '',

        'inprogress_end' => '',

        'identifier_noresep' => $obt['identifier_noresep'],
        'identifier_noresep_index' => $obt['identifier_noresep_index'],

        'local_id' => $shellMed["kode_brg"],

        'kfa_form' => '',
        'kfa_form.code' => '',
        'kfa_form.name' => '',

        'kfa_route' => '',
        'kfa_route.code' => '',
        'kfa_route.name' => '',

        'jumlah' => $shellMed['jumlah'],
        'kali' => $shellMed['kali'],
        'hari' => $shellMed['hari'],
        'aturanpakai' => SshtApiUtil::genAturanPakaiObat(
          kali: $shellMed['kali'],
          hari: $shellMed['hari'],
          sediaan: $shellMed['sediaan'],
          waktu: $shellMed['waktu'],
        ),

        'medication_type' => $medicationType,
        'medication_category' => 'community',
      ];

      // ==========================================
      // MEDICATION REQUEST
      // ==========================================

      $payloadMedication[$key] = array_merge(
        $basePayload,
        [
          'ingredient' => $ingredientRequest,
        ]
      );

      // ==========================================
      // MEDICATION DISPENSE
      // ==========================================

      $payloadMedicationDispense[$key] = array_merge(
        $basePayload,
        [
          'ingredient' => $ingredientDispense,
        ]
      );
    }

    // ==========================================
    // PRINT HASIL DI LUAR FOREACH
    // ==========================================

    echo "\n=== payload medication-request ===\n";
    print_r($payloadMedication);

    echo "\n=== payload medication-dispense ===\n";
    print_r($payloadMedicationDispense);
  }

  // /**
  //  * Run: php yii ssht-test-query/medication-ralan 2025-05-01 rm
  //  */
  // public static function actionMedicationRalan(string $tgl_param, string $rm)
  // {
  //   // $tgl_param = tanggal masuk pasien
  //   $dataObat = SshtApiQueryMapping::queryMedicationRalan($tgl_param, $rm);
  //
  //   if (empty($dataObat)) {
  //     echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
  //     // return ExitCode::OK;
  //   }
  //
  //   echo "Ditemukan Data obat: " . count($dataObat) . " data.\n";
  //   // print_r($dataEncounter[0]);
  //   print_r($dataObat);
  // }
}
