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

class SshtApiClientController extends Controller
{
  /**
   * Run: php yii ssht-api-client/send-task-ralan 2026-05-01
   * crontab: 30 19 * * * php yii ssht-api-client/send-task-ralan "$(date -d 'yesterday' +%Y-%m-%d)"
   * cron send on (h-1) now()-1day
   */
  public function actionSendTaskRalan(string $tgl_param)
  {
    // encounter & diagnosa
    $this->actionSendEncounterRalan($tgl_param);
    // observasi vital
    $this->actionSendObservationRalan($tgl_param);
    // general procedure
    $this->actionSendProcedureGeneralRalan($tgl_param);
    // serviceRequest Radiologi
    $this->actionSendServiceRequestRadio($tgl_param);
    // imagingStudy
    $this->actionSendImagingStudy($tgl_param);
    // observation & diagnosticReport Radiologi
    $this->actionSendObservationDanDiagnosticReportRadio($tgl_param);
    // medicationRequest & medicationDispense (inprogress)
    $this->actionGenerateMedicationRequestRalan($tgl_param);
    // send medicationRequest & medicationDispense runing on split cron
    // 15 * * * * php yii ssht-api-client/task-send-medication-request-ralan
    // 15 * * * * php yii ssht-api-client/task-send-medication-dispense-ralan
    // Lab - ServiceRequest & Speciment
    $this->actionSendServiceRequestAndSpecimentLabRalan($tgl_param);
    // Lab - Observation & DiagnosticReport (inprogress)
    // EncounterFinish (inprogress)
  }

  /**
   * Run: php yii ssht-api-client/send-task-ranap 2026-05-01
   */
  public function actionSendTaskRanap(string $tgl_param)
  {
    // RANAP - inprogress 
    // // encounter & diagnosa
    // $this->actionSendEncounterRanap($tgl_param);
    // // observasi vital
    // $this->actionSendObservationRanap($tgl_param);
    // // general procedure
    // $this->actionSendProcedureGeneralRanap($tgl_param);
    // // serviceRequest Radiologi
    // $this->actionSendServiceRequestRadio($tgl_param);
    // // imagingStudy
    // $this->actionSendImagingStudy($tgl_param);
    // // observation & diagnosticReport Radiologi
    // $this->actionSendObservationDanDiagnosticReportRadio($tgl_param);
    // medicationRequest & medicationDispense (inprogress)
    // Lab - ServiceRequest (inprogress)
    // Lab - Speciment (inprogress)
    // Lab - Observation & DiagnosticReport (inprogress)
    // EncounterFinish (inprogress)
  }

  /**
   * Run: php yii ssht-api-client/send-task-ugd 2026-05-01
   */
  public function actionSendTaskUgd(string $tgl_param)
  {
    // UGD - inprogress 
    // // encounter & diagnosa
    // $this->actionSendEncounterUgd($tgl_param);
    // // observasi vital
    // $this->actionSendObservationUgd($tgl_param);
    // // general procedure
    // $this->actionSendProcedureGeneralUgd($tgl_param);
    // // serviceRequest Radiologi
    // $this->actionSendServiceRequestRadio($tgl_param);
    // // imagingStudy
    // $this->actionSendImagingStudy($tgl_param);
    // // observation & diagnosticReport Radiologi
    // $this->actionSendObservationDanDiagnosticReportRadio($tgl_param);
    // medicationRequest & medicationDispense (inprogress)
    // Lab - ServiceRequest (inprogress)
    // Lab - Speciment (inprogress)
    // Lab - Observation & DiagnosticReport (inprogress)
    // EncounterFinish (inprogress)
  }

  private function parseIcdCodes($rawCodes)
  {
    if (empty($rawCodes)) return [];

    $mapping = [
      "R50.0" => "R50",
      // "A01.0" => "A01",
      // "Z00.0" => "Z00",
    ];

    $codes = explode(';', $rawCodes);
    $normalizedCodes = [];

    foreach ($codes as $c) {
      $clean = trim(preg_replace('/[^a-zA-Z0-9.]/', '', $c));
      if (!$clean) continue;

      if (isset($mapping[$clean])) {
        $clean = $mapping[$clean];
      }
      $normalizedCodes[] = $clean;
    }

    $uniqueCodes = array_unique($normalizedCodes);

    // Query menggunakan database terminologi
    return (new \yii\db\Query())
      ->select(['icd10_code as code', 'icd10_en as display'])
      ->from('icd10')
      ->where(['icd10_code' => $uniqueCodes])
      ->all(\Yii::$app->dbsshtterminologi);
  }

  private function parseIcd9Codes($rawCodes)
  {
    if (empty($rawCodes)) return [];

    // $mapping = [
    //   "R50.0" => "R50",
    //   // "A01.0" => "A01",
    //   // "Z00.0" => "Z00",
    // ];

    // $mapping = ["" => ""];
    //
    // $codes = explode(';', $rawCodes);
    // $normalizedCodes = [];
    //
    // foreach ($codes as $c) {
    //   $clean = trim(preg_replace('/[^a-zA-Z0-9.]/', '', $c));
    //   if (!$clean) continue;
    //
    //   if (isset($mapping[$clean])) {
    //     $clean = $mapping[$clean];
    //   }
    //   $normalizedCodes[] = $clean;
    // }

    // $uniqueCodes = array_unique($normalizedCodes);

    // Query menggunakan database terminologi
    return (new \yii\db\Query())
      ->select(['code', 'display'])
      ->from('icd9')
      // ->where(['code' => $uniqueCodes])
      ->where(['code' => $rawCodes])
      ->one(\Yii::$app->dbsshtterminologi);
  }

  private function generateEncounterTimes($a_end)
  {
    $ts_end = strtotime($a_end);
    $ts_arrived = $ts_end - (rand(15, 30) * 60); // Datang 15-30 mnt sebelumnya
    $ts_inprogress = $ts_end - (rand(5, 10) * 60); // Mulai diperiksa 5-10 mnt sebelum selesai

    return [
      'arrived_at' => date('Y-m-d H:i:s', $ts_arrived),
      'inprogress_at' => date('Y-m-d H:i:s', $ts_inprogress),
      'inprogress_end' => date('Y-m-d H:i:s', $ts_end),
    ];
  }

  public function actionTestHello()
  {
    $config = SshtApiBase::getConfig();
    echo "Hi.. \n";
    echo "config load: " . json_encode($config) . "\n";
    // return ExitCode::OK;
  }

  public static function actionEncounterRalanTest($tgl_param)
  {
    $dataEncounter = SshtApiQueryMapping::queryEncounterRalanSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";
    print_r($dataEncounter[0]);
  }


  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ralan 2026-05-01
   */
  public function actionSendEncounterRalan($tgl_param)
  {
    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    echo "--- TASK BOT SSHT START: " . $tgl_param . " ---\n";

    $dataEncounter = SshtApiQueryMapping::queryEncounterRalanSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";
    // print_r($dataEncounter);

    // foreach ($dataEncounter as $key => $row) {
    //   echo "data ke-" . $key;
    //   echo $row;
    // }

    // // 2026-05-06 12:03 - Testing payload testdata
    // $testdata1 = $dataEncounter[0];
    //
    // print_r($testdata1);
    //
    // $ktp = preg_replace('/\D/', '', $testdata1['ktp']);
    // $resPatient = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_BY_NIK, ['query' => ['id' => $ktp]]);
    // sleep(1);
    // $resPatientReq = json_decode((string) $resPatient->getBody(), true);
    // $pasienIhs = $resPatientReq['data']['idIHS'] ?? null;
    // print_r($pasienIhs);
    // $resLocation = SshtApiBase::request(SshtApiUrl::LOCATION_GET_BY_IHS, ['query' => ['id' => $testdata1['lokasi_ihs']]]);
    // $locationNamaReq = json_decode((string) $resLocation->getBody(), true);
    // print_r($locationNamaReq);
    // $locationNama = $locationNamaReq['data']['nama'] ?? 'Poliklinik';
    // print_r($locationNama);
    //
    // // 4. Generate Waktu (Format SQL: YYYY-MM-DD HH:mm:ss)
    // $times = $this->generateEncounterTimes($testdata1['a_end']);
    //
    // $payloadEncounter = [
    //   "pasien_idIHS" => $pasienIhs,
    //   "pasien_nama" => $testdata1['pasien_nama'],
    //   "pasien_rm" => $testdata1['rm'],
    //   "practitioner_idIHS" => $testdata1['dokter_ihs'],
    //   "practitioner_nama" => $testdata1['dokter_nama'],
    //   "location_idIHS" => $testdata1['lokasi_ihs'],
    //   "location_nama" => $locationNama,
    //   "location_poli" => $testdata1['poli'],
    //   "arrived_at" => $times['arrived_at'],
    //   "inprogress_at" => $times['inprogress_at'],
    //   "class" => "ralan"
    // ];
    //
    // echo "\nPayload EncounterRalanTest: " . json_encode($payloadEncounter) . "\n";
    //
    // $icdList = $this->parseIcdCodes($testdata1['icd_codes']);
    //
    // echo "\nicd10List parse: " . json_encode($icdList) . "\n";
    //
    // foreach ($icdList as $icd) {
    //   $payloadCondition = [
    //     "encounter_idIHS" => "xxxx",
    //     "patient_idIHS" => $pasienIhs,
    //     "patient_nama" => $testdata1['pasien_nama'],
    //     "conditionCode" => $icd['code'],
    //     "conditionName" => $icd['display'],
    //     "inprogress_start" => $times['inprogress_at'],
    //     "inprogress_end" => $times['inprogress_end']
    //   ];
    //   echo "\nPayload test untuk Condition/Diagnosa: " . json_encode($payloadCondition) . "\n";
    // }

    // 2026-05-05 06:45 - disable coba query dulu..
    // 2026-05-06 12:04 - test kirim data
    foreach ($dataEncounter as $row) {
      try {
        echo "\nProcessing RM: {$row['rm']}...";

        // 2. Get IHS Pasien via KTP (Wrapper)
        $ktp = preg_replace('/\D/', '', $row['ktp']);

        $resPatient = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_BY_NIK, ['query' => ['id' => $ktp]]);
        $resPatientReq = json_decode((string) $resPatient->getBody(), true);

        sleep(1);

        $pasienIhs = $resPatientReq['data']['idIHS'] ?? null;
        // $pasienIhs = $resPatient['data']['idIHS'] ?? null;

        if (!$pasienIhs) {
          echo " SKIPPED (IHS Pasien tidak ditemukan)";
          continue;
        }

        // 3. Get Nama Lokasi (Wrapper)
        $resLocation = SshtApiBase::request(SshtApiUrl::LOCATION_GET_BY_IHS, ['query' => ['id' => $row['lokasi_ihs']]]);

        $locationNamaReq = json_decode((string) $resLocation->getBody(), true);
        $locationNama = $locationNamaReq['data']['nama'] ?? 'Poliklinik';

        // 4. Generate Waktu (Format SQL: YYYY-MM-DD HH:mm:ss)
        $times = $this->generateEncounterTimes($row['a_end']);

        // 5. SEND ENCOUNTER (Sesuai Body JSON Gateway kamu)
        $payloadEncounter = [
          "pasien_idIHS" => $pasienIhs,
          "pasien_nama" => $row['pasien_nama'],
          "pasien_rm" => $row['rm'],
          "practitioner_idIHS" => $row['dokter_ihs'],
          "practitioner_nama" => $row['dokter_nama'],
          "location_idIHS" => $row['lokasi_ihs'],
          "location_nama" => $locationNama,
          "location_poli" => $row['poli'],
          "arrived_at" => $times['arrived_at'],
          "inprogress_at" => $times['inprogress_at'],
          "class" => "ralan"
        ];

        // --- DEBUG & CONFIRMATION ---
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::ENCOUNTER_CREATE),
          payload: $payloadEncounter,
        )) {
          continue;
        }

        $resEncReq = SshtApiBase::request(SshtApiUrl::ENCOUNTER_CREATE, ['json' => $payloadEncounter]);
        $resEnc = json_decode((string) $resEncReq->getBody(), true);
        $encounterIhsId = $resEnc['data']['idIHS'] ?? null;

        sleep(1);

        if ($encounterIhsId) {
          echo " SUCCESS ENCOUNTER: $encounterIhsId\n";
          // Ambil data hasil wrap gateway
          $encData = $resEnc['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_encounter', [
            'idIHS'              => $encData['idIHS'],
            'subject_rm'         => $encData['subject_rm'],
            'subject_idIHS'      => $encData['subject_idIHS'],
            'subject_nama'       => $encData['subject_nama'],
            'practition_idIHS'   => $encData['practition_idIHS'],
            'practition_lokalid' => $row['dokter'], // Ambil dari data lokal SIMRS kamu
            'practition_nama'    => $encData['practition_nama'],
            'location_idIHS'     => $encData['location_idIHS'],
            'location_nama'      => $encData['location_nama'],
            'organization_idIHS'  => $encData['organization_idIHS'],
            'arrived_start'      => $encData['arrived_at'],
            'arrived_end'        => $encData['arrived_end'],
            'inprogress_start'   => $encData['inprogress_start'],
            'inprogress_end'     => $times['inprogress_end'], // dari generator lokal
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
            'class'              => $encData['class'],
          ])->execute();

          // 6. SEND CONDITION (Looping ICD10)
          $icdList = $this->parseIcdCodes($row['icd_codes']);

          foreach ($icdList as $key => $icd) {
            $payloadCondition = [
              "encounter_idIHS" => $encounterIhsId,
              "patient_idIHS" => $pasienIhs,
              "patient_nama" => $row['pasien_nama'],
              "conditionCode" => $icd['code'],
              "conditionName" => $icd['display'],
              "inprogress_start" => $encData['inprogress_start'],
              "inprogress_end" => $times['inprogress_end']
            ];

            // --- DEBUG & CONFIRMATION ---
            if (!$debugger->allow(
              context: SshtApiUtil::genDebugContext(SshtApiUrl::CONDITION_CREATE),
              payload: $payloadCondition,
            )) {
              continue;
            }

            $resCondReq = SshtApiBase::request(SshtApiUrl::CONDITION_CREATE, ['json' => $payloadCondition]);
            sleep(2);
            $resCond = json_decode((string)$resCondReq->getBody(), true);
            $conditionIhsId = $resCond['data']['idIHS'] ?? null;

            if ($conditionIhsId) {
              $condData = $resCond['data'];

              \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_condition', [
                'condition_idIHS' => $conditionIhsId,
                'encounter_idIHS' => $encounterIhsId,
                'conditionRank'   => ($key == 0) ? 1 : 2,
                'code'            => $icd['code'],
                'display'         => $icd['display'],
                'rm'              => $row['rm'],
                'dok'             => $row['dokter'],
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
              ])->execute();
            }
            echo "   > Condition OK: " . ($condData['idIHS'] ?? 'FAILED') . " ({$icd['code']})\n";
          }
        } else {
          echo " FAILED ENCOUNTER";
          sleep(2);
        }
      } catch (\Exception $e) {
        echo " ERROR: " . $e->getMessage() . "\n";
        sleep(5);
      }
      // jeda rate limit gateway
      sleep(2);
    }

    echo "\n--- TASK BOT DONE: " . $tgl_param . " ---\n";
    // return ExitCode::OK;
  }

  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ugd 2026-05-01
   */
  public function actionSendEncounterUgd($tgl_param)
  {
    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    echo "--- TASK BOT SSHT START: " . $tgl_param . " ---\n";

    $dataEncounter = SshtApiQueryMapping::queryEncounterUgdSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";

    // 2026-05-12 08:16 - testing encounter ugd
    print_r($dataEncounter);
    // exit;

    foreach ($dataEncounter as $row) {
      try {
        echo "\nProcessing RM: {$row['rm']}...";

        // 2. Get IHS Pasien via KTP (Wrapper)
        $ktp = preg_replace('/\D/', '', $row['ktp']);

        $resPatient = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_BY_NIK, ['query' => ['id' => $ktp]]);
        $resPatientReq = json_decode((string) $resPatient->getBody(), true);

        sleep(1);

        $pasienIhs = $resPatientReq['data']['idIHS'] ?? null;
        // $pasienIhs = $resPatient['data']['idIHS'] ?? null;

        if (!$pasienIhs) {
          echo " SKIPPED (IHS Pasien tidak ditemukan)";
          continue;
        }

        // 3. Get Nama Lokasi (Wrapper)
        $resLocation = SshtApiBase::request(SshtApiUrl::LOCATION_GET_BY_IHS, ['query' => ['id' => $row['lokasi_ihs']]]);

        $locationNamaReq = json_decode((string) $resLocation->getBody(), true);
        $locationNama = $locationNamaReq['data']['nama'] ?? 'Poliklinik';

        // 4. Generate Waktu (Format SQL: YYYY-MM-DD HH:mm:ss)
        $times = $this->generateEncounterTimes($row['a_end']);

        // 5. SEND ENCOUNTER (Sesuai Body JSON Gateway kamu)
        $payloadEncounter = [
          "pasien_idIHS" => $pasienIhs,
          "pasien_nama" => $row['pasien_nama'],
          "pasien_rm" => $row['rm'],
          "practitioner_idIHS" => $row['dokter_ihs'],
          "practitioner_nama" => $row['dokter_nama'],
          "location_idIHS" => $row['lokasi_ihs'],
          "location_nama" => $locationNama,
          "location_poli" => $row['poli'],
          "arrived_at" => $times['arrived_at'],
          "inprogress_at" => $times['inprogress_at'],
          "class" => "ugd"
        ];

        // --- DEBUG & CONFIRMATION ---
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::ENCOUNTER_CREATE),
          payload: $payloadEncounter,
        )) {
          continue;
        }

        $resEncReq = SshtApiBase::request(SshtApiUrl::ENCOUNTER_CREATE, ['json' => $payloadEncounter]);
        $resEnc = json_decode((string) $resEncReq->getBody(), true);
        $encounterIhsId = $resEnc['data']['idIHS'] ?? null;

        sleep(1);

        if ($encounterIhsId) {
          echo " SUCCESS ENCOUNTER: $encounterIhsId\n";
          // Ambil data hasil wrap gateway
          $encData = $resEnc['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_encounter', [
            'idIHS'              => $encData['idIHS'],
            'subject_rm'         => $encData['subject_rm'],
            'subject_idIHS'      => $encData['subject_idIHS'],
            'subject_nama'       => $encData['subject_nama'],
            'practition_idIHS'   => $encData['practition_idIHS'],
            'practition_lokalid' => $row['dokter'], // Ambil dari data lokal SIMRS kamu
            'practition_nama'    => $encData['practition_nama'],
            'location_idIHS'     => $encData['location_idIHS'],
            'location_nama'      => $encData['location_nama'],
            'organization_idIHS'  => $encData['organization_idIHS'],
            'arrived_start'      => $encData['arrived_at'],
            'arrived_end'        => $encData['arrived_end'],
            'inprogress_start'   => $encData['inprogress_start'],
            'inprogress_end'     => $times['inprogress_end'], // dari generator lokal
            'created_at'         => date('Y-m-d H:i:s'),
            'updated_at'         => date('Y-m-d H:i:s'),
            'class'              => $encData['class'],
          ])->execute();

          // 6. SEND CONDITION (Looping ICD10)
          $icdList = $this->parseIcdCodes($row['icd_codes']);

          foreach ($icdList as $key => $icd) {
            $payloadCondition = [
              "encounter_idIHS" => $encounterIhsId,
              "patient_idIHS" => $pasienIhs,
              "patient_nama" => $row['pasien_nama'],
              "conditionCode" => $icd['code'],
              "conditionName" => $icd['display'],
              "inprogress_start" => $encData['inprogress_start'],
              "inprogress_end" => $times['inprogress_end']
            ];

            // --- DEBUG & CONFIRMATION ---
            if (!$debugger->allow(
              context: SshtApiUtil::genDebugContext(SshtApiUrl::CONDITION_CREATE),
              payload: $payloadCondition,
            )) {
              continue;
            }

            $resCondReq = SshtApiBase::request(SshtApiUrl::CONDITION_CREATE, ['json' => $payloadCondition]);
            sleep(2);
            $resCond = json_decode((string)$resCondReq->getBody(), true);
            $conditionIhsId = $resCond['data']['idIHS'] ?? null;

            if ($conditionIhsId) {
              $condData = $resCond['data'];

              \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_condition', [
                'condition_idIHS' => $conditionIhsId,
                'encounter_idIHS' => $encounterIhsId,
                'conditionRank'   => ($key == 0) ? 1 : 2,
                'code'            => $icd['code'],
                'display'         => $icd['display'],
                'rm'              => $row['rm'],
                'dok'             => $row['dokter'],
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
              ])->execute();
            }
            echo "   > Condition OK: " . ($condData['idIHS'] ?? 'FAILED') . " ({$icd['code']})\n";
          }
        } else {
          echo " FAILED ENCOUNTER";
          sleep(2);
        }
      } catch (\Exception $e) {
        echo " ERROR: " . $e->getMessage() . "\n";
        sleep(5);
      }
      // jeda rate limit gateway
      sleep(2);
    }

    echo "\n--- TASK BOT DONE: " . $tgl_param . " ---\n";
  }


  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ranap 2026-05-01
   */
  public function actionSendEncounterRanap($tgl_param)
  {
    echo "--- TASK BOT SSHT START: " . date('Y-m-d H:i:s') . " ---\n";
    echo "\n--- TASK BOT DONE ---\n";
  }

  /**
   * Run Cron: php yii ssht-api-client/send-encounter-finish-ralan 2025-12-01
   */
  public function actionSendEncounterFinishRalan($tgl_param)
  {
    $classEnc = 'AMB';
    $dbLocal = Yii::$app->sshtAPIdb;
    $config = SshtApiBase::getConfig();
    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );
    echo "--- TASK BOT SSHT EncounterFinish START: " . $tgl_param . " ---\n";
    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_lokalid',
        'practition_idIHS',
        'practition_nama',
        'location_idIHS',
        'location_nama',
        'arrived_start',
        'arrived_end',
        'inprogress_start',
        'inprogress_end',
        'finish_start',
        'finish_end',
        'class'
      ])->from('ssht_encounter')
      ->where(['like', 'arrived_start', $tgl_param . '%', false])
      ->andWhere(['class' => $classEnc])
      ->all($dbLocal);

    foreach ($encounter as $record) {
      // --- DEBUG & CONFIRMATION ---
      if (!$debugger->allow(
        context: SshtApiUtil::genDebugContext(SshtApiUrl::ENCOUNTER_GET),
        payload: $record['idIHS'],
      )) {
        continue;
      }
      $resEncReq = SshtApiBase::request(SshtApiUrl::ENCOUNTER_GET, ['query' => ['id' => $record['idIHS']]]);
      $resEnc = json_decode((string) $resEncReq->getBody(), true);
      $resEncData = isset($resEnc['data']) ? $resEnc['data'] : [];
      $encounterIhsId = $resEnc['data']['idIHS'] ?? null;

      echo "\nData Encounter Get: \n";
      print_r($resEnc);

      // if (!$debugger->allow(
      //   context: ['method' => 'Updated Class Encounter di local?'],
      //   payload: $encounterIhsId,
      // )) {
      //   continue;
      // }
      //
      // sleep(1);

      if ($resEncReq->getStatusCode() == 200 && $resEnc['status'] == 'true') {
        // $payload = [
        //   'idIHS' => $resEncData['idIHS'],
        //   'arrived_start' => $resEncData['arrived_start'],
        //   'arrived_end' => $resEncData['arrived_end'],
        //   'inprogress_start' => $resEncData['inprogress_start'],
        //   'inprogress_end' => $resEncData['inprogress_end'],
        //   'finish_start' => $resEncData['finish_start'],
        //   'finish_end' => $resEncData['finish_end'],
        //   'class' => $resEncData['class']
        // ];
        //
        // $dbLocal->createCommand()->update('ssht_encounter', $payload, ['idIHS' => $encounterIhsId])->execute();

        // $getconditionsSimrs = SshtApiQueryMapping::queryConditionSimrs(
        //   tgl_param: $tgl_param,
        //   rm: $record['subject_rm'],
        //   dok: $record['practition_lokalid'],
        //   poli_idihs: $record['location_idIHS']
        // );

        $dbSimrs = Yii::$app->db;

        $getconditionsSimrs = (new Query())
          ->select(['k.Id', 'k.rm', 'k.icd', 'k.dokter', 'k.poli', 'mpoli.idihs'])
          ->from('mr_kunjungan k')
          ->leftJoin('mpoli', 'k.poli = mpoli.poli')
          ->where([
            'k.rm' => $record['subject_rm'],
            'k.dokter' => $record['practition_lokalid'],
            'k.tanggal' => $tgl_param,
            'mpoli.idihs' => $record['location_idIHS'],
          ])
          ->orderBy("Id ASC")
          ->all($dbSimrs);

        $getConditionLocal = (new Query())
          ->select([
            'condition_idIHS',
            'encounter_idIHS',
            'code',
            'display',
            'conditionRank'
          ])->from('ssht_condition')
          ->where(['encounter_idIHS' => $encounterIhsId])
          ->all($dbLocal);

        // // --- DEBUG & CONFIRMATION ---
        // if (!$debugger->allow(
        //   context: SshtApiUtil::genDebugContext(SshtApiUrl::CONDITION_GET_BY_ENCOUNTER),
        //   payload: $record['idIHS'],
        // )) {
        //   continue;
        // }

        // 1. Ambil urutan ICD dari SIMRS sebagai acuan utama
        // Kita bersihkan karakter non-alphanumeric agar 'I24.9' dan 'I249' tetap cocok
        $simrsOrder = [];
        foreach ($getconditionsSimrs as $index => $row) {
          $cleanIcd = preg_replace('/[^A-Za-z0-9]/', '', $row['icd']);
          $simrsOrder[$cleanIcd] = $index + 1; // Rank dimulai dari 1
        }

        // 2. Map data lokal berdasarkan acuan di atas
        $diagnosis = array_map(function ($localItem) use ($simrsOrder) {
          $cleanLocalCode = preg_replace('/[^A-Za-z0-9]/', '', $localItem['code']);

          // Cari rank berdasarkan urutan di SIMRS
          $rank = isset($simrsOrder[$cleanLocalCode]) ? $simrsOrder[$cleanLocalCode] : 99;

          return [
            'condition_idIHS' => $localItem['condition_idIHS'],
            'code'            => $localItem['code'],
            'display'         => $localItem['display'],
            'conditionRank'   => (string) $rank,
          ];
        }, $getConditionLocal);

        // 3. Urutkan hasil akhir agar Rank 1 (Primary) berada di index [0] payload
        usort($diagnosis, function ($a, $b) {
          return (int)$a['conditionRank'] <=> (int)$b['conditionRank'];
        });

        $payloadEncounterFinish = [
          'encounter_idIHS' => $encounterIhsId,
          'patient_idIHS' => $record['subject_idIHS'],
          'patient_nama' => $record['subject_nama'],

          'practition_idIHS' => $record['practition_idIHS'],
          'practition_nama' => $record['practition_nama'],

          'location_idIHS' => $record['location_idIHS'],
          'location_nama' => $record['location_nama'],

          'diagnosis' => $diagnosis,

          'arrived_start' => $record['arrived_start'],
          'arrived_end' => $record['arrived_end'],

          'inprogress_start' => $record['inprogress_start'],
          'inprogress_end' => $record['inprogress_end'],

          'finish_start' => $record['finish_start'],
          'finish_end' => $record['finish_end'],

          'class' => $resEncData['class'],
        ];

        // --- DEBUG & CONFIRMATION ---
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::ENCOUNTER_FINISH),
          payload: $payloadEncounterFinish,
        )) {
          continue;
        }

        // $encounterFinish = SshtApiBase::request(SshtApiUrl::ENCOUNTER_FINISH, ['json' => $payloadEncounterFinish]);

        // if ($encounterFinish->getStatusCode() == 200 || $encounterFinish->getStatusCode() == 201) {
        // }


        // end if encounter get
      }
      // end foreach $encounter record
    }
    echo "\n--- TASK BOT DONE ---\n";
  }

  public function actionSendCondition($tgl_param) {}

  public function actionSendObservationVitalTd($tgl_param) {}

  public function actionSendObservationVitalNapas($tgl_param) {}

  public function actionSendObservationVitalSuhu($tgl_param) {}

  public function actionSendAllergy($tgl_param) {}

  public function actionSendCompositionDiet($tgl_param) {}

  public function actionSendServiceRequestEkg($tgl_param) {}
  public function actionSendServiceRequestEco($tgl_param) {}
  public function actionSendServiceRequestNebulasi($tgl_param) {}

  public function actionSendProcedureDanObservationEkg($tgl_param) {}
  public function actionSendProcedureDanObservationEco($tgl_param) {}
  public function actionSendProcedureDanObservationNebulasi($tgl_param) {}


  /**
   * Run Cron: php yii ssht-api-client/lab-ralan-test 2026-05-01 rm
   */
  public static function actionLabRalanTest(string $tgl_param, string $rm_param)
  {
    $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm_param);

    if (empty($dataLabRalan)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataLabRalan) . " data.\n";
    print_r($dataLabRalan);
    // print_r($dataLabRalan[0]);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-lab-dan-speciment-ralan-single 2026-05-01 rm
   */
  public function actionSendServiceRequestLabDanSpecimentRalanSingle(
    string $tgl_param,
    string $rm
  ) {
    $class = 'AMB';

    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $encounters = (new Query())
      ->select(['idIHS', 'subject_rm', 'practition_lokalid', 'inprogress_start', 'inprogress_end', 'class'])
      ->from('ssht_encounter')
      ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->andWhere(['subject_rm' => $rm])
      ->andWhere(['class' => $class])
      ->all($dbLocal);

    if (empty($encounters)) {
      $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
      return;
    }

    foreach ($encounters as $enc) {
      $rm = $enc['subject_rm'];

      $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm);

      if (!$dataLabRalan || empty($dataLabRalan)) {
        $this->stdout("[-] SKIP: RM $rm tydac ada order Lab\n");
        continue;
      }

      // print_r($dataLabRalan);

      $dataPreReqLab = $dataLabRalan["prereq"];

      foreach ($dataLabRalan['lab_result'] as $lab) {

        // payload ServiceRequestLab
        $payload = [
          "sampleID" => $lab["svc_req"]["service_req_code"],
          "category" => 'lab', // snomed code from (lab)
          "serviceReqCode" => $lab["svc_req"]["loinc_order_code"], // loinc code
          "serviceReqDisplay" => $lab["svc_req"]["loinc_order_display"], // loinc display
          "reason" => $lab["svc_req"]["nama_lokal"], // text
          "encounter_idIHS" => $enc["idIHS"],
          "dokter" => $enc["practition_lokalid"],
          "rm" => $enc["subject_rm"],
          "petugaslab_idIHS" => $dataPreReqLab["petugas_ihs"],
          "petugaslab_nama" => $dataPreReqLab["petugas_nama"]
        ];

        print_r($payload);

        $now = date('Y-m-d H:i:s');

        // $dbLocal->createCommand()->insert('ssht_servicerequest', [
        //   'servicerequest_idIHS' => '',
        //   'encounter_idIHS' => $payload['encounter_idIHS'],
        //   'rm' => $rm,
        //   'patient_idIHS' => $enc['subject_idIHS'] ?? null,
        //   'petugas_idIHS' => $payload['petugas_idIHS'],
        //   'petugas_nama' => $payload['petugas_nama'],
        //   'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
        //   'dok' => $payload['dokter'] ?? null,
        //   'date' => $enc['inprogress_end'],
        //   'created_at' => $now,
        //   'updated_at' => $now,
        // ])->execute();
        //
        // $idSr = $dbLocal->getLastInsertID();

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::SERVICE_REQUEST_CREATE_LAB),
          payload: $payload,
        )) {
          continue;
        }

        // // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_LAB,
          ['json' => $payload]
        );

        $result = json_decode((string) $response->getBody(), true);
        print_r(json_encode($result));

        if (isset($result['status']) && ($result['status'] == 'true')) {
          $data_api = $result['data'] ?? [];
          $sr_id_ihs = $data_api['servicerequest_idIHS'] ?? null;

          // 4. Save to Local DB
          $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          $dbLocal->createCommand()->insert(
            'ssht_servicerequest',
            [
              'servicerequest_idIHS' => $sr_id_ihs,
              'encounter_idIHS' => $enc['idIHS'],
              'acsn' => $data_api['acsn'] ?? null,
              'category_code' => $data_api['category_code'] ?? null,
              'category_display' => $data_api['category_display'] ?? null,
              'code' => $data_api['code'] ?? null,
              'display' => $data_api['display'] ?? null,
              'perihal' => $data_api['perihal'] ?? null,
              'rm' => $rm,
              'patient_idIHS' => $data_api['patient_idIHS'] ?? null,
              'petugas_idIHS' => $payload['petugaslab_idIHS'],
              'petugas_nama' => $payload['petugaslab_nama'],
              'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
              'dok' => $data_api['dok'] ?? null,
              'date' => $data_api['date'],
              'status' => 'active',
              'created_at' => $now,
              'updated_at' => $now,
              'srid' => $data_api['srid'],

              // 'payload' => json_encode($payload),
              // 'send_status' => 'S',
            ]
            // ],
            // [
            //   'id' => $idSr,
            // ]
          )->execute();
        } else {
          // $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          //   'ssht_servicerequest',
          //   [
          //     'payload' => json_encode($payload),
          //     'send_status' => 'F',
          //     'send_error_code' => $response->getStatusCode(),
          //     'send_error_message' => isset($result["errors"]) ? json_encode($result['errors']) : $response->getMessage(),
          //     'updated_at' => $now,
          //   ],
          //   [
          //     'id' => $idSr,
          //   ]
          // )->execute();
          continue;
        }

        // SPECIMENT

        $payloadSpeciment = [
          "sampleID" => $lab["specimen"]["sample_id"],
          "servicerequest_idIHS" => $sr_id_ihs,
          "encounter_idIHS" => $enc["idIHS"],
          "speciment_code" => $lab["specimen"]["specimen_code"],
          "speciment_display" => $lab["specimen"]["specimen_display"],
          "sampling_method" => $lab["specimen"]["sampling_method"],
          "rm" => $rm,
          "dok" => $enc['practition_lokalid'],
        ];

        $cekSpeciment = (new Query())
          ->select([
            'ssp.id',
            'ssp.speciment_idIHS',
            'ssp.servicerequest_idIHS',
            'ssp.lokal_sampleID_testID',
            'ssp.method_code',
            'ssp.method_display',
            'ssp.code',
            'ssp.display',
            'ssp.date',
            'ssp.status',
          ])
          ->from('ssht_speciment ssp')
          ->where(['ssp.lokal_sampleID_testID' => $payloadSpeciment])
          ->exists($dbLocal);

        if ($cekSpeciment) {
          $this->stdout("[+] Skip Send cuz Duplicate Speciment detected, Rm: {$rm}, lokal_sampleID_testID: {$payloadSpeciment['sampleID']} \n");
          continue;
        }

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::SPECIMENT_CREATE),
          payload: $payloadSpeciment,
        )) {
          continue;
        }

        $this->sendSpecimentRalanSingle(
          $rm,
          $enc,
          $payload,
          $lab,
          $dataLabRalan,
          $payloadSpeciment,
          $sr_id_ihs
        );
      }
    }
  }

  /**
   * Run Cron: php yii ssht-api-client/send-observation-lab-ralan-single 2026-05-01 rm
   */
  public function actionSendObservationLabRalanSingle(
    string $tgl_param,
    string $rm
  ) {
    $class = 'AMB';

    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $serviceRequestLab = (new Query())
      ->select([
        'ssr.servicerequest_idIHS',
        'ssr.encounter_idIHS',
        'ssr.category_code',
        'ssr.category_display',
        'ssr.code as sr_code',
        'ssr.display as sr_display',
        'ssr.perihal',
        'ssr.rm',
        'ssr.patient_idIHS',
        'ssr.dok',
        'ssr.dokter_request_idIHS',
        'ssr.petugas_idIHS',
        'ssr.petugas_nama',
        'ssr.date',
        // 'ssr.class',
        'ssr.status',
        'ssr.srid',
        'ssp.speciment_idIHS',
        'ssp.lokal_sampleID_testID',
        'ssp.lokal_sampleID_testID',
        'ssp.method_code',
        'ssp.method_display',
        'ssp.code as sp_code',
        'ssp.display as sp_display',
      ])
      ->from('ssht_servicerequest ssr')
      ->leftJoin('ssht_speciment ssp', 'ssr.servicerequest_idIHS = ssp.servicerequest_idIHS')
      ->where(['CAST(ssr.date AS DATE)' => $tgl_param])
      ->andWhere(['ssr.rm' => $rm])
      ->andWhere(['ssr.status' => 'active'])
      ->andWhere(['ssr.category_code' => '108252007'])
      ->andWhere(['ssr.category_display' => 'Laboratory procedure'])
      ->all($dbLocal);

    if (empty($serviceRequestLab)) {
      $this->stdout("[!] Tydac ada data order serviceRequestLab tanggal {$tgl_param}\n");
      return;
    }

    foreach ($serviceRequestLab as $srlab) {
      $srrm = $srlab['rm'];

      $obsLabs = SshtApiQueryMapping::getObservationLabLocalRalan(
        $tgl_param,
        $srrm,
        $srlab["sr_code"]
      );

      if (!$obsLabs) {
        $this->stdout("[!] Skip: tydac ditemukan data Hasil Observasi lab Rm: {$srrm} ,tanggal {$tgl_param}\n");
        continue;
      }

      foreach ($obsLabs as $obsLab) {
        // 1. cek $obsLab loinc dengan param di loinc_lab (mengkasifikasi tipe isian berdasarkan jenis panel pemeriksaan)
        $labloinc = (new Query())
          ->select([
            'code',
            'display',
            'tipe_hasil_pemeriksaan',
            'scale',
            'unit_of_measure',
            'code_system'
          ])
          ->from('loinc_lab')
          ->where(['code' => $obsLab["loinc"]])
          ->one(\Yii::$app->dbsshtterminologi);

        if (empty($labloinc)) {
          $this->stdout("[!] Skip: tydac ditemukan kode loinc untuk data Hasil Observasi lab dengan kode lokal: {$obsLab['TEST_ID']} ,Rm: {$srrm} , tanggal {$tgl_param}\n");
          continue;
        }

        // 2. Payload Observation Lab bentukan disesuaikan tipe panel
        // ["Quantitative(Qn)", "Nominal(Nom)", "Ordinal(Ord)", "Narative(Nar)"]
        // sementara pake Quantitative sek untuk darah rutin kedepan kalau rampung mapping 
        // pake all type..
        $payloadObs = [
          "servicerequest_idIHS" => $srlab["servicerequest_idIHS"],
          "speciment_idIHS" => $srlab["speciment_idIHS"],
          "sampelID_TestID" => $obsLab["SAMPEL_ID"] . '-' . $obsLab["TEST_ID"],
          "rm" => $srrm,
          "laborat" => $srlab["petugas_idIHS"],
          // // data-init
          // // panel-type
          "scale" => $labloinc["scale"] == '-'
            ? $labloinc["tipe_hasil_pemeriksaan"]
            : $labloinc["scale"],
          // // observation
          "code-obs" => $labloinc["code"],
          "code-display" => $labloinc["display"],
          // // referenceRange skala untuk kondisi normal
          // "referenceRange" => str_contains($obsLab["ANGKA_NORMAL"], '-') && explode("-", $obsLab["ANGKA_NORMAL"])
          //   ? explode("-", $obsLab["ANGKA_NORMAL"])
          //   : '',
          "referenceRange" => (
            ($range = array_map('trim', explode('-', $obsLab["ANGKA_NORMAL"], 2))) &&
            isset($range[1], $range[0]) &&
            $range[0] !== '' &&
            $range[1] !== ''
          )
            ? $range
            : $obsLab["ANGKA_NORMAL"],
          "unit-referenceRange" => $labloinc["unit_of_measure"],
          // // codeableConcept (Ordinal & Nominal)
          // "code-codeableConcept" => "sometimes|alpha_dash",
          // "display-codeableConcept" => "sometimes|string",
          // // valueQuantity (Quantitative)
          "valueQuantity" => $obsLab["RESULT_VALUE"],
          "unit-valueQuantity" => $labloinc["unit_of_measure"],
          // // valueString (Narative)
          // "valueString" => "sometimes|string",
          // // interpretation (Quantitative) tapi saat ini di disable dulu aja belum ada source truth baku
          // "code-interpretation" => "sometimes|alpha_dash",
          // "display-interpretation" => "sometimes|string",
          // "interpretation" => "sometimes|string",
        ];
        // 3. Check Observation local db
        $checkObsLokal = (new Query())
          ->select(['sso.*'])
          ->from('ssht_observation sso')
          ->where([
            'sso.encounter_idIHS' => $srlab["encounter_idIHS"],
            'rm' => $rm,
            'status' => 'active',
            'obs_code' => $payloadObs["code-obs"],
          ])
          // ->andWhere(['sso.servicerequest_idIHS' => $srlab["servicerequest_idIHS"]])
          // ->andWhere(['sso.speciment_idIHS' => $srlab["speciment_idIHS"]])
          //
          // ->andWhere(['rm' => $rm])
          // ->andWhere(['status' => 'active'])
          // ->andWhere(['obs_code' => $payloadObs["code-obs"]])
          // ->one($dbLocal);
          ->exists($dbLocal);

        if ($checkObsLokal) {
          $this->stdout("[!] Skip: duplicate data Hasil Observasi lab dengan kode lokal: {$obsLab['TEST_ID']} ,Rm: {$srrm} , tanggal {$tgl_param}\n");
          // $this->stdout("{json_encode($checkObsLokal)}");
          continue;
        }
        // 4. Send Observation Lab
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::OBSERVATION_CREATE_LAB),
          payload: $payloadObs,
        )) {
          continue;
        }

        // // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::OBSERVATION_CREATE_LAB,
          ['json' => $payloadObs]
        );

        $result = json_decode((string) $response->getBody(), true);
        print_r(json_encode($result));

        // // 5. if sucess send save to local
        if (isset($result['status']) && ($result['status'] == 'true')) {
          $data_api = $result['data'] ?? [];

          $dbLocal->createCommand()->insert('ssht_observation', [
            'observation_idIHS' => $data_api['observation_idIHS'],
            'encounter_idIHS' => $data_api['encounter_idIHS'],
            'subject_idIHS' => $data_api['subject_idIHS'],
            'obs_code' => $data_api['obs_code'],
            'obs_display' => $data_api['obs_display'],
            'obs_value' => $data_api['obs_value'] ?? "",
            'obs_valueString' => $data_api['obs_valueString'],
            'date' => $data_api['date'],
            'rm' => $srrm,
            'status' => $data_api['status'],
            'created_at' => date('Y-m-d H:i:s'),
            'obs_system' => $data_api['obs_system'],
            'category_system' => $data_api['category_system'],
            'category_code' => $data_api['category_code'],
            'category_display' => $data_api['category_display'],

            // 2026-07-07 08:14 - disable baseOn itu dinamis tidak bisa di set static
            // 'speciment_idIHS' => $data_api['speciment_idIHS'],
            // "servicerequest_idIHS" => $data_api['servicerequest_idIHS'],
            "codeable_value" => $data_api['codeable_value'] ?? "",
            "codeable_display" => $data_api['codeable_display'] ?? "",
            'intr_code' => $data_api['intr_code'] ?? "",
            'intr_display' => $data_api['intr_display'] ?? "",
            'date' => $data_api['date'],
            'performer_idIHS' => $data_api['performer'],
          ])->execute();

          $this->stdout("  [OK] Observation Lab Saved: {$data_api['observation_idIHS']} ,from serviceRequest: {$payloadObs['servicerequest_idIHS']}, RM: {$srrm}\n");
          // 6. else gagal send
        } else {
          $this->stdout("  [Failed] data Hasil Observasi lab dengan kode lokal: {$obsLab['TEST_ID']} ,from serviceRequest: {$payloadObs['servicerequest_idIHS']} ,Rm: {$srrm} , tanggal {$tgl_param}\n\n");
          continue;
        }
        // end foreach obslab
      }
    }
  }

  /**
   * Run: php yii ssht-api-client/send-diagnostic-report-lab-ralan-single 2025-09-29 rm
   */
  public function actionSendDiagnosticReportLabRalanSingle(string $tgl_param, string $rm)
  {
    $class = 'AMB';

    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $serviceRequestLab = (new Query())
      ->select([
        'ssr.servicerequest_idIHS',
        'ssr.encounter_idIHS',
        'ssr.category_code',
        'ssr.category_display',
        'ssr.code as sr_code',
        'ssr.display as sr_display',
        'ssr.perihal',
        'ssr.rm',
        'ssr.patient_idIHS',
        'ssr.dok',
        'ssr.dokter_request_idIHS',
        'ssr.petugas_idIHS',
        'ssr.petugas_nama',
        'ssr.date',
        // 'ssr.class',
        'ssr.status',
        'ssr.srid',
        'ssp.speciment_idIHS',
        'ssp.lokal_sampleID_testID',
        'ssp.method_code',
        'ssp.method_display',
        'ssp.code as sp_code',
        'ssp.display as sp_display',
      ])
      ->from('ssht_servicerequest ssr')
      ->leftJoin('ssht_speciment ssp', 'ssr.servicerequest_idIHS = ssp.servicerequest_idIHS')
      ->where(['CAST(ssr.date AS DATE)' => $tgl_param])
      ->andWhere(['ssr.rm' => $rm])
      ->andWhere(['ssr.status' => 'active'])
      ->andWhere(['ssr.category_code' => '108252007'])
      ->andWhere(['ssr.category_display' => 'Laboratory procedure'])
      // ->groupBy(['ssr.servicerequest_idIHS'])
      ->all($dbLocal);

    if (empty($serviceRequestLab)) {
      $this->stdout("[!] Tydac ada data order serviceRequestLab tanggal {$tgl_param}\n");
      return;
    }

    foreach ($serviceRequestLab as $srlab) {

      $checkDr = (new Query())
        ->from('ssht_diagnosticreport')
        ->where(['rm' => $srlab['rm']])
        ->andWhere(['like', 'date', $tgl_param])
        ->andWhere(['servicerequest_idIHS' => $srlab['servicerequest_idIHS']])
        ->andWhere(['category_code' => 'LAB'])
        ->exists($dbLocal);

      if ($checkDr) {
        $this->stdout("[!] Skip: DiagnosticReport sudah ada untuk serviceRequestLab: {$srlab['servicerequest_idIHS']}, RM: {$srlab['rm']} , tanggal {$tgl_param}\n");
        continue;
      }

      if (!$debugger->allow(
        context: SshtApiUtil::genDebugContext(SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB),
        payload: [
          "servicerequest_idIHS" => $srlab["servicerequest_idIHS"],
          "value" => "-",
          "speciment_idIHS" => $srlab["speciment_idIHS"],
          // "sampelID_testID" => "" // atau pake srid? nick ganti DRLAB{xx}Q{xx}
          "srid" => $srlab["srid"] // atau pake srid? nick ganti DRLAB{xx}Q{xx}
        ],
      )) {
        continue;
      }

      $resR = SshtApiBase::request(SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB, [
        'json' => [
          "servicerequest_idIHS" => $srlab["servicerequest_idIHS"],
          "value" => "-",
          "speciment_idIHS" => $srlab["speciment_idIHS"],
          // "sampelID_testID" => "" // atau pake srid?
          "srid" => $srlab["srid"] // atau pake srid?
        ]
      ]);

      // $resRReq = json_decode((string) $resR->getBody(), true);
      // print_r($resRReq);
      // print_r(json_encode($resRReq));

      if ($resR->getStatusCode() == 200 || $resR->getStatusCode() == 201) {
        $resRReq = json_decode((string) $resR->getBody(), true);
        print_r($resRReq);
        print_r(json_encode($resRReq));
        $resDataR = $resRReq['data'] ?? [];
        $dbLocal->createCommand()->insert('ssht_diagnosticreport', [
          'diagnosticreport_idIHS' => $resDataR['diagnosticreport_idIHS'],
          'encounter_idIHS' => $resDataR['encounter_idIHS'],
          'servicerequest_idIHS' => $srlab["servicerequest_idIHS"],
          'subject_idIHS' => $resDataR['subject_idIHS'],
          'rm' => $srlab['rm'],
          'date' => $resDataR['date'],
          'status' => $resDataR['status'],
          'created_at' => date('Y-m-d H:i:s'),
          'category_system' => $resDataR['category_system'],
          'category_display' => $resDataR['category_display'],
          'category_code' => $resDataR['category_code'],
        ])->execute();
        $this->stdout("  [OK] Report Saved: {$srlab['rm']}\n");
      } else {
        $this->stdout("  [Gagal] Gagal save di db, diagnosticreport_idIHS: {$resDataR['diagnosticreport_idIHS']}, RM {$srlab['rm']}.\n");
      }
    }
  }

  private function sendSpecimentRalanSingle(
    $rm,
    $enc,
    $payload,
    $lab,
    $dataPreReqLab,
    $payloadSpeciment,
    $sr_id_ihs
  ) {
    $class = 'AMB';

    $dbLocalSpeciment = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    // // save payload speciment ke db..
    // $now = date('Y-m-d H:i:s');
    // $dbLocalSpeciment->createCommand()->insert('ssht_speciment', [
    //   'speciment_idIHS' => '',
    //   'servicerequest_idIHS' => $sr_id_ihs,
    //   'encounter_idIHS' => $payloadSpeciment['encounter_idIHS'],
    //   'rm' => $rm,
    //   'patient_idIHS' => $enc['subject_idIHS'] ?? null,
    //   'petugas_idIHS' => $payload['petugas_idIHS'],
    //   'petugas_nama' => $payload['petugas_nama'],
    //   'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
    //   'dok' => $payloadSpeciment['dokter'] ?? null,
    //   "sampleID" => $lab["specimen"]["sample_id"],
    //   "encounter_idIHS" => $enc["idIHS"],
    //   "speciment_code" => $lab["specimen"]["specimen_code"],
    //   "speciment_display" => $lab["specimen"]["specimen_display"],
    //   "sampling_method" => $lab["specimen"]["sampling_method"],
    //   'updated_at' => $now,
    // ])->execute();
    //
    // // id Speciment
    // $idSpec = $dbLocalSpeciment->getLastInsertID();

    $response = SshtApiBase::request(
      SshtApiUrl::SPECIMENT_CREATE,
      ['json' => $payloadSpeciment]
    );

    $result = json_decode((string)$response->getBody(), true);

    if (isset($result['status']) && ($result['status'] == 'true')) {
      $data_api = $result['data'] ?? [];

      $spe_id_ihs = $data_api['speciment_idIHS'] ?? null;

      // 4. Save to Local DB
      $now = date('Y-m-d H:i:s');

      try {
        //code...
        // $dbLocalSpeciment->createCommand()->update(
        $dbLocalSpeciment->createCommand()->insert(
          'ssht_speciment',
          [
            'speciment_idIHS' => $spe_id_ihs,
            'servicerequest_idIHS' => $data_api['servicerequest_idIHS'],
            'lokal_sampleID_testID' => $data_api['lokal_sampleID_testID'],
            // 'encounter_idIHS' => $enc['idIHS'],
            // 'acsn' => $data_api['acsn'] ?? null,
            'method_code' => $data_api['method_code'] ?? null,
            'method_display' => $data_api['method_display'] ?? null,
            'code' => $data_api['code'] ?? null,
            'display' => $data_api['display'] ?? null,
            'rm' => $rm,
            'dok' => $data_api['dok'] ?? null,
            // 'patient_idIHS' => $data_api['patient_idIHS'] ?? null,
            'laborat_ihs' => $payload['petugaslab_idIHS'],
            'laborat_nama' => $payload['petugaslab_nama'],
            // 'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
            'date' => $data_api['date'],
            // 'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,

            // 'payload' => json_encode($payload),
            // 'send_status' => 'S',
          ]
          // ],
          // [
          //   'id' => $idSpec
          // ]
        )->execute();
        $this->stdout("[+] Sukses Speciment sampleID {$payloadSpeciment['sampleID']}, Rm: {$rm}, Speciment_idIHS: {$spe_id_ihs} \n");
      } catch (\Throwable $e) {

        $this->stdout("[+] Gagal Save Duplicate Speciment, Rm: {$rm}, lokal_sampleID_testID: {$payloadSpeciment['sampleID']} \n");
        continue;
      }
    } else {
      // $now = date('Y-m-d H:i:s');
      // $dbLocalSpeciment->createCommand()->update(
      //   'ssht_speciment',
      //   [
      //     'payload' => json_encode($payload),
      //     'send_status' => 'F',
      //     'send_error_code' => $response->getStatusCode(),
      //     'send_error_message' => isset($result["errors"]) ? json_encode($result['errors']) : $response->getMessage(),
      //     'updated_at' => $now,
      //   ],
      //   [
      //     'id' => $idSpec
      //   ]
      // )->execute();
      $this->stdout("[-] Failed Speciment sampleID {$payloadSpeciment['sampleID']}, Rm: {$enc['rm']} \n");
      continue;
    }
  }

  public function actionSendSpecimentRalanSingle(string $tgl_param, string $rm) {}

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-and-speciment-lab-ralan 2026-05-01
   */
  public function actionSendServiceRequestAndSpecimentLabRalan(string $tgl_param)
  {
    $class = 'AMB';

    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $encounters = (new Query())
      ->select(['idIHS', 'subject_rm', 'practition_lokalid', 'inprogress_start', 'inprogress_end', 'class'])
      ->from('ssht_encounter')
      ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->andWhere(['class' => $class])
      ->all($dbLocal);

    if (empty($encounters)) {
      $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
      return;
    }

    foreach ($encounters as $enc) {
      $rm = $enc['subject_rm'];

      $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm);

      if (!$dataLabRalan || empty($dataLabRalan)) {
        $this->stdout("[-] SKIP: RM $rm tydac ada order Lab\n");
        continue;
      }

      $dataPreReqLab = $dataLabRalan["prereq"];

      foreach ($dataLabRalan['lab_result'] as $lab) {

        // payload ServiceRequestLab
        $payload = [
          "sampleID" => $lab["svc_req"]["service_req_code"],
          "category" => 'lab', // snomed code from (lab)
          "serviceReqCode" => $lab["svc_req"]["loinc_order_code"], // loinc code
          "serviceReqDisplay" => $lab["svc_req"]["loinc_order_display"], // loinc display
          "reason" => $lab["svc_req"]["nama_lokal"], // text
          "encounter_idIHS" => $enc["idIHS"],
          "dokter" => $enc["practition_lokalid"],
          "rm" => $enc["subject_rm"],
          "petugaslab_idIHS" => $dataPreReqLab["petugas_ihs"],
          "petugaslab_nama" => $dataPreReqLab["petugas_nama"]
        ];

        // $now = date('Y-m-d H:i:s');
        // $dbLocal->createCommand()->insert('ssht_servicerequest', [
        //   'servicerequest_idIHS' => '',
        //   'encounter_idIHS' => $payload['encounter_idIHS'],
        //   'rm' => $rm,
        //   'patient_idIHS' => $enc['subject_idIHS'] ?? null,
        //   'petugas_idIHS' => $payload['petugas_idIHS'],
        //   'petugas_nama' => $payload['petugas_nama'],
        //   'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
        //   'dok' => $payload['dokter'] ?? null,
        //   'date' => $enc['inprogress_end'],
        //   'created_at' => $now,
        //   'updated_at' => $now,
        //   'payload' => $payload,
        //   'send_status' => 'P',
        // ])->execute();
        //
        // $idSr = $dbLocal->getLastInsertID();

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::SERVICE_REQUEST_CREATE_LAB),
          payload: $payload,
        )) {
          continue;
        }

        // // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_LAB,
          ['json' => $payload]
        );

        $result = json_decode((string)$response->getBody(), true);
        print_r(json_encode($result));
        // exit;

        if (isset($result['status']) && ($result['status'] == 'true')) {
          $data_api = $result['data'] ?? [];
          $sr_id_ihs = $data_api['servicerequest_idIHS'] ?? null;

          // 4. Save to Local DB
          $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          $dbLocal->createCommand()->insert(
            'ssht_servicerequest',
            [
              'servicerequest_idIHS' => $sr_id_ihs,
              'encounter_idIHS' => $enc['idIHS'],
              'acsn' => $data_api['acsn'] ?? null,
              'category_code' => $data_api['category_code'] ?? null,
              'category_display' => $data_api['category_display'] ?? null,
              'code' => $data_api['code'] ?? null,
              'display' => $data_api['display'] ?? null,
              'perihal' => $data_api['perihal'] ?? null,
              'rm' => $rm,
              'patient_idIHS' => $data_api['patient_idIHS'] ?? null,
              'petugas_idIHS' => $payload['petugaslab_idIHS'],
              'petugas_nama' => $payload['petugaslab_nama'],
              'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
              'dok' => $data_api['dok'] ?? null,
              'date' => $data_api['date'],
              'status' => 'active',
              'crated_at' => $now, // for insert
              'updated_at' => $now,
              'srid' => $data_api['srid'],

              // 'payload' => json_encode($payload),
              // 'send_status' => 'S',
            ]
            // ],
            // [
            //   'id' => $idSr
            //   // 'rm' => $enc['rm'],
            //   // 'date' => $data_api['date'],
            //   // 'encounter_idIHS' => $enc['idIHS']
            // ]
          )->execute();
          $this->stdout("[+] Sukses ServiceRequest Lab: {json_encode($payload)}, Rm: {$rm}, ServiceRequest: {$sr_id_ihs} \n");
        } else {
          // $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          //   'ssht_servicerequest',
          //   [
          //     'payload' => json_encode($payload),
          //     'send_status' => 'F',
          //     'send_error_code' => $response->getStatusCode(),
          //     'send_error_message' => isset($result["errors"]) ? json_encode($result['errors']) : $response->getMessage(),
          //     'updated_at' => $now,
          //   ],
          //   [
          //     'id' => $idSr,
          //   ]
          // )->execute();
          $this->stdout("[-] Failed ServiceRequest {json_encode($payload)}, Rm: {$enc['rm']} \n");
          continue;
        }

        // SPECIMENT
        // payload Speciment
        $payloadSpeciment = [
          "sampleID" => $lab["specimen"]["sample_id"],
          "servicerequest_idIHS" => $sr_id_ihs,
          "encounter_idIHS" => $enc["idIHS"],
          "speciment_code" => $lab["specimen"]["specimen_code"],
          "speciment_display" => $lab["specimen"]["specimen_display"],
          "sampling_method" => $lab["specimen"]["sampling_method"],
          "rm" => $rm,
          "dok" => $enc['practition_lokalid'],
        ];

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::SPECIMENT_CREATE),
          payload: $payloadSpeciment,
        )) {
          continue;
        }

        $this->sendSpecimentRalanSingle(
          $rm,
          $enc,
          $payload,
          $lab,
          $dataLabRalan,
          $payloadSpeciment,
          $sr_id_ihs
        );
      }
      // endforeach encounter
    }
  }

  public function actionSendServiceRequestLab($tgl_param) {}
  public function actionSendSpecimentLab($tgl_param) {}
  public function actionSendObservationDanDiagnosticReportLab($tgl_param) {}

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-radio 2026-05-01
   */
  public function actionSendServiceRequestRadio($tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {
      // 1. Ambil data encounter dari DB Lokal
      $encounters = (new Query())
        ->select(['idIHS', 'subject_rm', 'inprogress_start', 'class'])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        // ->andWhere(['class' => $class])
        ->all($dbLocal);

      if (empty($encounters)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        // 2. Cari detail order di SIMRS
        // $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio($enc['class'], $rm, $tgl_param);
        // $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio($enc['class'], $rm, $tgl_param);

        // $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio('EMER', $rm, $tgl_param);
        // $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio('INP', $rm, $tgl_param);
        $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio('AMB', $rm, $tgl_param);

        if (!$simrs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada order radiologi\n");
          continue;
        }

        // Persiapkan Payload sesuai wrapper SshtApiBase
        $payload = [
          "noradio" => $simrs['noradio'],
          "tagging" => $simrs['kode'],
          "loinc" => $simrs['loinc'] ?? "",
          "category" => "radio",
          "reason" => $simrs['indikasi'] ?: "Permintaan Radiologi",
          "encounter_idIHS" => $enc['idIHS'],
          "dokter" => trim($simrs['dkirim']),
          "rm" => $rm,
          "petugas_idIHS" => $simrs['idssht'] ?? "",
          "petugas_nama" => trim($simrs['nm_user'] ?? "")
        ];

        // --- DEBUG & CONFIRMATION ---
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::SERVICE_REQUEST_CREATE_RAD),
          payload: $payload,
        )) {
          continue;
        }

        // // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_RAD,
          ['json' => $payload]
        );

        $result = json_decode((string)$response->getBody(), true);

        if (isset($result['status']) && ($result['status'] == 'true' || $result['status'] === true)) {
          $data_api = $result['data'] ?? [];
          $sr_id_ihs = $data_api['servicerequest_idIHS'] ?? null;

          if ($sr_id_ihs) {
            // 4. Save to Local DB
            $now = date('Y-m-d H:i:s');
            $dbLocal->createCommand()->insert('ssht_servicerequest', [
              'servicerequest_idIHS' => $sr_id_ihs,
              'encounter_idIHS' => $enc['idIHS'],
              'acsn' => $data_api['acsn'] ?? null,
              'category_code' => $data_api['category_code'] ?? null,
              'category_display' => $data_api['category_display'] ?? null,
              'code' => $data_api['code'] ?? null,
              'display' => $data_api['display'] ?? null,
              'perihal' => $data_api['perihal'] ?? null,
              'rm' => $rm,
              'patient_idIHS' => $data_api['patient_idIHS'] ?? null,
              'petugas_idIHS' => $payload['petugas_idIHS'],
              'petugas_nama' => $payload['petugas_nama'],
              'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
              'dok' => $data_api['dok'] ?? null,
              'date' => $data_api['date'],
              'status' => 'active',
              'created_at' => $now,
              'updated_at' => $now,
              'srid' => $data_api['srid'] ?? null,
            ])->execute();

            $this->stdout("[OK] RM: $rm | SR_ID: $sr_id_ihs\n");
          }
        } else {
          $errMsg = $result['error'] ?? "Unknown Error";
          $this->stdout("[ERR] RM: $rm | " . Json::encode($errMsg) . "\n");
        }
      }
    } catch (\Exception $e) {
      $this->stdout("[CRITICAL] " . $e->getMessage() . "\n");
    }
  }

  /**
   * Jalankan dengan: php yii ssht-api-client/send-imaging-study 2026-05-01
   */
  public function actionSendImagingStudy($tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;
    $config = SshtApiBase::getConfig();
    $orthancUrl = $config["orthanc_url"]; // Sesuaikan URL Orthanc
    // $orthancAuth = [$config["orthanc_auth_user"], $config["orthanc_auth_password"]]; // Sesuaikan user:pass Orthanc
    $orthancAuth = $config["orthanc_auth_user"] . ":" . $config["orthanc_auth_password"];
    $dicomRouterName = $config["dicom_router_name"]; // Sesuaikan nama modality di Orthanc

    // print_r($orthancUrl);
    // print_r($orthancAuth);

    try {
      // 1. Ambil data Service Request dari DB Lokal
      $records = (new Query())
        ->select(['servicerequest_idIHS', 'encounter_idIHS', 'acsn', 'display', 'rm', 'patient_idIHS'])
        ->from('ssht_servicerequest')
        ->where(['CAST(date AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (empty($records)) {
        $this->stdout("[!] Gak ada data ServiceRequest untuk tanggal {$tgl_param}\n");
        return;
      }

      $client = new Client([
        'base_uri' => $config["orthanc_url"],
        'auth' => [$config["orthanc_auth_user"], $config["orthanc_auth_password"]],
        'timeout'  => 30.0,
      ]);


      foreach ($records as $row) {
        $acsn_db = $row['acsn'];
        $patient_id_ihs = $row['patient_idIHS'];
        $rm_nomor = $row['rm'];

        // Parsing ACSN (Format: noradio-kode)
        $parts = explode('-', $acsn_db);
        if (count($parts) < 2) {
          $this->stdout("  [!] Skip: Format ACSN salah pada RM {$rm_nomor}: {$acsn_db}\n");
          continue;
        }

        $noradio = $parts[0];
        $new_acsn = $acsn_db; // noradio-kode

        $this->stdout("\n[*] Processing RM: {$rm_nomor} | PatientID IHS: {$patient_id_ihs}\n");
        $this->stdout("[*] ACSN: {$noradio} -> {$new_acsn}\n");

        // 2. Cari Study ID di Orthanc berdasarkan AccessionNumber lama (noradio)
        $findRes = $client->post('/tools/find', [
          'json' => [
            'Level' => 'Study',
            'Query' => ['AccessionNumber' => (string)$noradio]
          ]
        ]);
        $studies = json_decode($findRes->getBody(), true);

        if (empty($studies)) {
          $this->stdout("  [-] Skip: Study tidak ditemukan di Orthanc untuk ACSN {$noradio}\n");
          continue;
        }

        print_r($studies);

        foreach ($studies as $study_id) {
          // 3. Modify: Buat versi baru dengan metadata lengkap (PatientID IHS & ACSN Baru)
          $modifyRes = $client->post("/studies/{$study_id}/modify", [
            'json' => [
              'Replace' => [
                'AccessionNumber' => $new_acsn,
                'PatientID' => (string)$patient_id_ihs,
              ],
              'Force' => true
            ]
          ]);

          $dataModify = json_decode($modifyRes->getBody(), true);

          if (!empty($dataModify)) {
            $new_study_id = $dataModify['ID'];
            $this->stdout("  [OK] Metadata modified. New Orthanc ID: {$new_study_id}\n");

            // 4. HAPUS STUDY LAMA
            $client->delete("/studies/{$study_id}");
            $this->stdout("  [OK] Study lama ({$study_id}) telah dihapus dari Orthanc.\n");

            // 5. Kirim ID BARU ke DICOM Router
            $storeRes = $client->post("/modalities/{$dicomRouterName}/store", [
              'body' => $new_study_id
            ]);

            if ($storeRes->getStatusCode() == 200) {
              $this->stdout("  [OK] Berhasil kirim ke {$dicomRouterName} dengan ACSN {$new_acsn}\n");
            } else {
              $this->stdout("  [!] Gagal kirim ke Router: " . $storeRes->content . "\n");
            }
          } else {
            $this->stdout("  [!] Gagal modifikasi metadata Study {$study_id}\n");
          }
        }
      }
    } catch (\Exception $e) {
      $this->stdout("[CRITICAL] Error: " . $e->getMessage() . "\n");
    }
  }

  /**
   * php yii ssht-api-client/send-observation-dan-diagnostic-report-radio 2026-05-01
   */
  public function actionSendObservationDanDiagnosticReportRadio($tgl_param)
  {
    $config = SshtApiBase::getConfig();

    $dbLocal = Yii::$app->sshtAPIdb;

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {
      $this->stdout("[*] Menarik data imaging tanggal: {$tgl_param}...\n");

      // 1. Tarik list Imaging Study dari Gateway via Wrapper Base
      $respImaging = SshtApiBase::request(SshtApiUrl::IMAGINGSTUDY_GET_BYDATE, [
        'query' => ['date' => $tgl_param]
      ]);

      $respImagingReq = json_decode((string) $respImaging->getBody(), true);
      $items = $respImagingReq['data'] ?? [];
      $this->stdout("[*] Menemukan " . count($items) . " data untuk diproses.\n");

      foreach ($items as $master) {
        $imgIdIhs = $master['idIHS'] ?? null;
        if (!$imgIdIhs) continue;

        try {

          if (!$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::IMAGINGSTUDY_GET),
            payload: ["imagingstudy_idIHS" => $imgIdIhs],
          )) {
            continue;
          }

          // 2. Get Detail Imaging untuk dapat ACSN & ServiceRequest ID
          $respDetail = SshtApiBase::request(SshtApiUrl::IMAGINGSTUDY_GET, [
            'query' => ['id' => $imgIdIhs]
          ]);
          $detailDataReq = json_decode((string) $respDetail->getBody(), true);
          $detailData = $detailDataReq['data'] ?? null;
          if (!$detailData) continue;

          $srIdIhs = $detailData['servicerequest_idIHS'];
          $acsnFull = $detailData['acsn'] ?? "";
          $noradio = strpos($acsnFull, '-') !== false ? explode('-', $acsnFull)[0] : $acsnFull;

          // 3. Query Hasil Expertise di SIMRS
          $row = SshtApiQueryMapping::queryObservationDanDiagnosticReportSimrsRadio($noradio);

          if (!$row || empty($row['hasil'])) {
            $this->stdout("[-] Data SIMRS tidak ditemukan/hasil kosong: $noradio\n");
            continue;
          }

          // list($obsText, $impressionText) = $this->parseExpertiseRtf($row['hasil']);
          list($obsText, $impressionText) = SshtApiUtil::parseExpertiseRdHasilRtf($row['hasil']);

          // --- 4. PROSES OBSERVATION (TEMUAN) ---
          if (!empty($obsText)) {
            $checkObs = (new Query())
              ->from('ssht_observation')
              ->where(['rm' => $row['rm']])
              ->andWhere(['like', 'date', $tgl_param])
              ->exists($dbLocal);

            if (!$checkObs) {
              // param
              // "servicerequest_idIHS" => "required|uuid",
              // "imagingstudy_idIHS" => "required|uuid",
              // "rm" => "required|alpha_dash",
              // "valueString" => "sometimes|string",

              if (!$debugger->allow(
                context: SshtApiUtil::genDebugContext(SshtApiUrl::OBSERVATION_CREATE_RAD),
                payload: [
                  "servicerequest_idIHS" => $srIdIhs,
                  "imagingstudy_idIHS" => $imgIdIhs,
                  "rm" => $row['rm'],
                  "valueString" => $obsText
                ],
              )) {
                continue;
              }

              $resO = SshtApiBase::request(SshtApiUrl::OBSERVATION_CREATE_RAD, [
                'json' => [
                  "servicerequest_idIHS" => $srIdIhs,
                  "imagingstudy_idIHS" => $imgIdIhs,
                  "rm" => $row['rm'],
                  "valueString" => $obsText
                ]
              ]);

              if ($resO->getStatusCode() == 200 || $resO->getStatusCode() == 201) {
                $resOReq = json_decode((string) $resO->getBody(), true);
                print_r($resOReq);
                $resDataO = $resOReq['data'] ?? [];
                $dbLocal->createCommand()->insert('ssht_observation', [
                  'observation_idIHS' => $resDataO['observation_idIHS'],
                  'encounter_idIHS' => $resDataO['encounter_idIHS'],
                  'subject_idIHS' => $resDataO['subject_idIHS'] ?? $resDataO['subject_idIdIHS'],
                  'obs_code' => $resDataO['obs_code'],
                  'obs_display' => $resDataO['obs_display'],
                  'obs_valueString' => $resDataO['obs_valueString'],
                  'date' => $resDataO['date'],
                  'rm' => $row['rm'],
                  'status' => $resDataO['status'],
                  'created_at' => date('Y-m-d H:i:s'),
                  'obs_system' => $resDataO['obs_system'],
                  'category_system' => $resDataO['category_system'],
                  'category_code' => $resDataO['category_code'],
                  'category_display' => $resDataO['category_display'],
                ])->execute();
                $this->stdout("  [OK] Observation Saved: {$row['rm']}\n");
              }
            } else {
              $this->stdout("  [SKIP] Observation RM {$row['rm']} sudah ada.\n");
            }
          }

          // --- 5. PROSES DIAGNOSTIC REPORT (KESAN) ---
          if (!empty($impressionText)) {
            $checkDr = (new Query())
              ->from('ssht_diagnosticreport')
              ->where(['rm' => $row['rm']])
              ->andWhere(['like', 'date', $tgl_param])
              ->exists($dbLocal);

            if (!$checkDr) {

              if (!$debugger->allow(
                context: SshtApiUtil::genDebugContext(SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_RAD),
                payload: [
                  "servicerequest_idIHS" => $srIdIhs,
                  "value" => $impressionText,
                  "noradio" => $noradio
                ],
              )) {
                continue;
              }

              $resR = SshtApiBase::request(SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_RAD, [
                'json' => [
                  "servicerequest_idIHS" => $srIdIhs,
                  "value" => $impressionText,
                  "noradio" => $noradio
                ]
              ]);

              if ($resR->getStatusCode() == 200 || $resR->getStatusCode() == 201) {
                $resRReq = json_decode((string) $resR->getBody(), true);
                print_r($resRReq);
                $resDataR = $resRReq['data'] ?? [];
                $dbLocal->createCommand()->insert('ssht_diagnosticreport', [
                  'diagnosticreport_idIHS' => $resDataR['diagnosticreport_idIHS'],
                  'encounter_idIHS' => $resDataR['encounter_idIHS'],
                  'servicerequest_idIHS' => $srIdIhs,
                  'subject_idIHS' => $resDataR['subject_idIHS'],
                  'rm' => $row['rm'],
                  'date' => $resDataR['date'],
                  'status' => $resDataR['status'],
                  'created_at' => date('Y-m-d H:i:s'),
                  'category_system' => $resDataR['category_system'],
                  'category_display' => $resDataR['category_display'],
                  'category_code' => $resDataR['category_code'],
                ])->execute();
                $this->stdout("  [OK] Report Saved: {$row['rm']}\n");
              }
            } else {
              $this->stdout("  [SKIP] Report RM {$row['rm']} sudah ada.\n");
            }
          }
        } catch (\Exception $itemErr) {
          $this->stdout(" [!] Gagal memproses: " . $itemErr->getMessage() . "\n");
          continue;
        }
      }
    } catch (\Exception $e) {
      $this->stdout("\n[CRITICAL] Error Global: " . $e->getMessage() . "\n");
    }
  }

  /**
   * php yii ssht-api-client/send-observation-ralan-test 2026-05-01 rm
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
   * php yii ssht-api-client/send-observation-ralan 2026-05-01
   */
  // public function actionSendObservationRalan($tanggal, $rm)
  public function actionSendObservationRalan($tgl_param)
  {
    $config = SshtApiBase::getConfig();

    $dbLocal = Yii::$app->sshtAPIdb;

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $encounters = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'subject_nama',
          'practition_lokalid',
          'practition_idIHS',
          'inprogress_start',
          'class'
        ])->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        // ->andWhere(['class' => $class])
        ->all($dbLocal);

      // Yii::info(json_encode($encounters), 'log-send-observation-ralan-get-encounter-data');
      print_r($encounters[0]);
      print_r($encounters[1]);
      print_r($encounters[2]);
      print_r($encounters[3]);
      print_r($encounters[4]);
      echo "\n...\n";
      echo "ditemukan " . count($encounters) . " data.\n";
      // exit;

      if (empty($encounters)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        $simrsobs = SshtApiQueryMapping::queryObservationRalan($tgl_param, $rm);
        // print_r($simrsobs);

        if (!$simrsobs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada data observasi vital\n");
          continue;
        }

        $mapping = [
          'systolic' => 'tdsys',
          'diastolic' => 'tddias',
          'heart_rate' => 'nadi',
          'body_temp' => 'suhu',
          'respiratory_rate' => 'nafas',
        ];

        $obsdata = $simrsobs['observation_data'];

        foreach ($mapping as $key => $obsName) {

          if (!isset($obsdata[$key])) {
            continue;
          }

          $payload = [
            "encounterIdIHS" => $enc['idIHS'],
            "obs_name" => $obsName,
            "obs_value" => $obsdata[$key],
            "patient_idIHS" => $enc['subject_idIHS'],
            "patient_nama" => $enc['subject_nama'],
            "practition_idIHS" => $enc['practition_idIHS'],
            "inprogress_start" => $enc['inprogress_start'],
            "rm" => $rm,
            "dok" => $enc['practition_lokalid']
          ];

          // debugger
          if (!$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::OBSERVATION_CREATE_VITAL),
            payload: $payload,
          )) {
            continue;
          }

          $response = SshtApiBase::request(
            SshtApiUrl::OBSERVATION_CREATE_VITAL,
            ['json' => $payload]
          );

          $result = json_decode((string)$response->getBody(), true);

          // $this->stdout()
          $this->stdout("[PROCESS] RM $rm\n");
          $this->stdout((string)$response->getBody());

          if (isset($result['status']) && ($result['status'] == 'true' || $result['status'] === true)) {
            $data_api = $result['data'] ?? [];
            $obs_id_ihs = $data_api['observation_idIHS'] ?? null;

            if ($obs_id_ihs) {
              // 4. Save to Local DB
              $now = date('Y-m-d H:i:s');
              $dbLocal->createCommand()->insert('ssht_observation', [
                'observation_idIHS' => $data_api['observation_idIHS'],
                'encounter_idIHS' => $data_api['encounter_idIHS'],
                'subject_idIHS' => $data_api['subject_idIHS'],
                'obs_system' => $data_api['obs_system'],
                'obs_code' => $data_api['obs_code'],
                'obs_display' => $data_api['obs_display'],
                'obs_value' => $data_api['obs_value'],
                'date' => $data_api['date'],
                'rm' => $rm,
                'status' => $data_api['status'],
                'created_at' => $now,
                'category_system' => $data_api['category_system'],
                'category_code' => $data_api['category_code'],
                'category_display' => $data_api['category_display'],
              ])->execute();

              $this->stdout("[OK] RM: $rm | SR_ID: $obs_id_ihs\n");
            }
          } else {
            $errMsg = $result['error'] ?? "Unknown Error";
            $this->stdout("[ERR] RM: $rm | " . Json::encode($errMsg) . "\n");
          }
          // end for mapping obs key
        }
        // end for encounter data
      }
    } catch (\Exception $e) {
      $this->stdout("[CRITICAL] " . $e->getMessage() . "\n");
    }
  }

  /**
   * php yii ssht-api-client/procedure-general-test 2025-09-27 055129
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
   * php yii ssht-api-client/send-procedure-general-ralan 2026-05-01
   */
  public function actionSendProcedureGeneralRalan($tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    echo "--- TASK BOT SSHT START: " . $tgl_param . " ---\n";

    try {

      $encounters = (new Query())
        ->select(['idIHS', 'subject_rm', 'practition_idIHS', 'inprogress_start', 'class'])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['class' => 'AMB'])
        ->all($dbLocal);

      if (empty($encounters)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r($encounters);

      foreach ($encounters as $enc) {

        $rm = $enc['subject_rm'];

        $simrs = SshtApiQueryMapping::queryProcedureGeneral($tgl_param, $rm);

        if (!$simrs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada ProcedureGeneral\n");
          continue;
        }

        print_r($simrs);
        // $this->stdout("[>] simrs-query: \n");
        // $this->stdout($simrs);
        // $this->stdout("\n");

        $mapping = [
          'icd9' => 'proc_code',
          'desc' => 'proc_display',
        ];

        $pdata = $simrs['procedures'];

        // print_r('pdata');
        print_r($pdata);
        // $this->stdout($pdata);

        foreach ($pdata as $p) {

          // foreach ($mapping as $key => $obsName) {
          //
          //   if (!isset($p[$key])) {
          //     continue;
          //   }

          // $icdList = $this->parseIcd9Codes($p[$key]);
          // //
          // print_r('ini icdList');
          // print_r($icdList);
          // //
          // if (!$icdList) {
          //   $this->stdout("[-] SKIP: RM $rm icd9: " . $p[$key] . " tydac Tidak Valid (ICD-9 CM 2010) \n");
          //   continue;
          // }

          // payload Procedure
          $payloadProcedure = [
            "encounterIdIHS" => $enc['idIHS'] ?? "",
            // "proc_code" => $icdList['code'] ?? "", // if general proc icd-9 code elif diagnostik
            // "proc_display" => $icdList['display'] ?? "", // if general proc icd-9 display
            "proc_code" => $p['icd9'] ?? "", // if general proc icd-9 code elif diagnostik
            "proc_display" => $p['desc'] ?? "", // if general proc icd-9 display
            "status" => "completed", // completed, entered-in-error, not-done, in-progres
            "category" => "general", // general, edukasi dkk,
            "dok" => $simrs['kode_dokter'] ?? "", // nik dokter
            "rm" => $simrs['rm_pasien'] ?? "", // rm local
            "datetime" => $enc['inprogress_start'] ?? "" // bisa pake inprogress_start
          ];

          // --- DEBUG & CONFIRMATION ---
          if (!$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::PROCEDURE_CREATE),
            payload: $payloadProcedure,
          )) {
            continue;
          }

          $resProcReq = SshtApiBase::request(SshtApiUrl::PROCEDURE_CREATE, ['json' => $payloadProcedure]);

          $resProc = json_decode((string) $resProcReq->getBody(), true);

          // print_r($resProcReq->getBody());
          $this->stdout("[+] body-response: \n");
          print_r($resProc);
          // $this->stdout("$resProc \n");
          // echo "   > Procedure OK: $resProc\n";
          // exit;

          // if duplicate
          if ($resProcReq->getStatusCode() == 400 && $resProc['errors']['code'] == 'duplicate') {
            continue;
          }

          // 2026-06-04 08:58 - disable dulu testing body response..
          $procedureIhsId = $resProc['data']['procedure_idIHS'] ?? null;
          sleep(1);

          if ($procedureIhsId) {
            $procData = $resProc['data'];

            \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_procedure', [
              'procedure_idIHS' => $procedureIhsId,
              'encounter_idIHS' => $enc['idIHS'],
              'code'            => $procData['code'],
              'display'         => $procData['display'],
              'system'         => $procData['system'],
              'category_code'   => $procData['category_code'],
              'category_display' => $procData['category_display'],
              'category_system' => $procData['category_system'],
              'subject_idIHS'   => $procData['subject_idIHS'],
              'practition_idIHS' => $procData['practition_idIHS'],
              'rm'              => $procData['rm'],
              'dok'             => $procData['dok'],
              'date'            => $procData['date'],
              'status'          => $procData['status'],
              'created_at'      => date('Y-m-d H:i:s'),
              'updated_at'      => date('Y-m-d H:i:s')
            ])->execute();

            // echo "   > Procedure OK: " . ($procedureIhsId ?? 'FAILED') . " ({$icdList['code']})\n";
            echo "   > Procedure OK: " . ($procedureIhsId ?? 'FAILED') . " ({$p['icd9']} - {$p['desc']})\n";
            print_r($procData);
          } else {
            echo " FAILED Procedure";
            sleep(2);
          }
          // }
        }
        // end foreach encounter
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(5);
    }
    // jeda rate limit gateway
    sleep(2);
    // end foreach $dataProcedure
  }

  /**
   * php yii ssht-api-client/sync-refobatbriging-medication-single
   */
  public function actionSyncRefobatbrigingMedicationSingle($local_id)
  {
    // $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    // $debugger = new SshtApiDebugger(
    //   enabled: $config['debug']
    // );

    try {

      $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId($local_id);

      if (!$getLocalObt) {
        $this->stdout("[-] tydac ada local_id tsb..\n");
        return;
        // continue;
      }

      if ($getLocalObt["medication_idIHS"]) {
        $this->stdout("[-] sudah dimaping..\n");
        return;
      }

      $payloadMedication = [
        "local_id" => (string) $getLocalObt['id_local'],
        "kfa_code" => $getLocalObt['kfa_code'],
        "kfa_display" => $getLocalObt['kfa_display'],
        "kfa_bza" => json_decode($getLocalObt['kfa_bza'], true),
        "kfa_form" => json_decode($getLocalObt['kfa_form'], true),
        "kfa_route" => json_decode($getLocalObt['kfa_route'], true),
      ];

      // if (!$debugger->allow(
      //   context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
      //   payload: $payloadMedication,
      // )) {
      //   continue;
      // }

      // $this->stdout("[-] test..\n");
      // exit;

      $response = SshtApiBase::request(
        SshtApiUrl::MEDICATION_CREATE,
        [
          'json' => $payloadMedication
        ]
      );

      $resReq = json_decode((string) $response->getBody(), true);

      if ($response->getStatusCode() == 400 && $resReq['errors']['code'] == 'duplicate') {
        return;
      }

      // print_r($resProcReq->getBody());
      $this->stdout("[+] body-response: \n");
      print_r($resReq);

      $medication_idIHS = $resReq['data']['medication_idIHS'] ?? null;
      sleep(1);

      if ($medication_idIHS) {
        // $MedReqData = $resReq['data'];
        // simpan medication_idIHS
        Yii::$app->db->createCommand()->update(
          'ref_obat_briging',
          [
            'medication_idIHS' => $medication_idIHS,
            'updated_at' => date('Y-m-d H:i:s'),
          ],
          [
            'id_local' => $local_id,
            'kfa_code' => $getLocalObt['kfa_code'],
          ]
        )->execute();

        $this->stdout("[+] Sukses sync\n");
        $this->stdout("[+] medication_idIHS: {$medication_idIHS} \n");
        $this->stdout("[+] local_id: {$local_id} \n");
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      // sleep(3);
    }
  }

  /**
   * php yii ssht-api-client/test-wrapper-send-medication-ralan-single 2026-05-01 $rm
   */
  public function actionTestWrapperSendMedicationRalanSingle(string $tgl_param, string $rm_param): void
  {
    $this->actionTestSendMedicationRequestRalanSingle($tgl_param, $rm_param);
    // $this->actionTestSendMedicationDispenseRalanSingle($tgl_param, $rm_param);
  }

  /**
   * php yii ssht-api-client/test-send-medication-request-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationRequestRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $encounter = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'subject_nama',
          'practition_idIHS',
          'practition_nama',
          'practition_lokalid',
          'inprogress_start',
          'inprogress_end',
          'class'
        ])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['subject_rm' => $rm])
        ->andWhere(['class' => 'AMB'])
        ->one($dbLocal);

      if (empty($encounter)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r($encounter);

      $rm = $encounter['subject_rm'];
      $encounterIdIHS = $encounter['idIHS'];

      $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

      if (!$simrs) {
        $this->stdout("[-] SKIP: RM $rm tydac ada ProcedureGeneral\n");
        return;
        // continue;
      }

      print_r($simrs);

      foreach ($simrs as $key => $obt) {

        $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

        $payloadMedication = [
          "encounter_idIHS" => $encounterIdIHS,
          "patient_idIHS" => $encounter['subject_idIHS'],
          "patient_nama" => $encounter['subject_nama'],
          "rm" => $encounter['subject_rm'],
          "dok" => $encounter['practition_lokalid'],
          "practition_idIHS" => $encounter['practition_idIHS'],
          "practition_nama" => $encounter['practition_nama'],
          "inprogress_end" => $encounter['inprogress_end'],
          "identifier_noresep" => $identifier_resep->identifier_noresep,
          "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
          "local_id" => (string) $obt['id_local'],
          "kfa_code" => $obt['kfa_code'],
          "kfa_display" => $obt['kfa_display'],
          "kfa_bza" => json_decode($obt['kfa_bza'], true),
          "kfa_form" => json_decode($obt['kfa_form'], true),
          "kfa_route" => json_decode($obt['kfa_route'], true),
          "jumlah" => (string) trim($obt['jumlah']),
          "kali" => (string) trim($obt['kali']),
          "hari" => (string) trim($obt['hari'])
        ];

        //
        // print_r(json_encode($payloadMedication));
        // exit;


        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
          payload: $payloadMedication,
        )) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_REQUEST_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationRequest_idIHS = $resMedReq['data']['medicationRequest_idIHS'] ?? null;
        sleep(1);

        if ($medicationRequest_idIHS) {
          $MedReqData = $resMedReq['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
            'medicationrequest_idIHS' => $medicationRequest_idIHS, // uuid
            'encounter_idIHS' => $encounterIdIHS, // uuid

            'identifier_noresep' => $MedReqData["identifier_noresep"],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code'   => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm'              => $MedReqData['rm'], // string:7
            'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30
            'dok'             => $MedReqData['dok'], // string:14
            'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30
            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'], // text
            'expected_supply_duration' => $MedReqData['expected_supply_duration'], // text
            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'], // text

            'performer_org_idIHS' => $MedReqData['performer_org_idIHS'], // string:30 - organisasi farmasi ralan

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:30
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20
            'authored_on'   => $MedReqData['authored_on'], // date (y-m-d H:i:s) -> nullable true
            'validity_period_start' => $MedReqData['validity_period_start'], // date (y-m-d H:i:s)
            'validity_period_end' => $MedReqData['validity_period_end'], // date (y-m-d H:i:s)
            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status'          => $MedReqData['status'], // string:30
            'local_id' => $MedReqData['local_id'],

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $simrs['ihs_petugas'], // string:30
            'petugas_nama' => $simrs['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $simrs['ihs_petugas_ambil'], // string:30
            'petugas_ambil_nama' => $simrs['petugas_ambil_nama'], // string:30

            // timestamp
            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationRequest OK: " . ($medicationRequest_idIHS ?? 'FAILED') . "\n";
          print_r($MedReqData);
        } else {
          echo " FAILED MedicationRequest";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function actionTestQueryMedicationRequestRalanSingle
  }


  /**
   * php yii ssht-api-client/test-send-medication-dispense-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationDispenseRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      // payload dari MedicationRequest yang sudah dikirim sebelumnya..
      $medicationRequest = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationRequest) {
        $this->stdout("[-] SKIP: RM $rm tydac ada MedicationRequest\n");
        return;
        // continue;
      }

      print_r($medicationRequest);

      foreach ($medicationRequest as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
          // mapping dari ref
          "jumlah" => (string) $obt['quantity_value'],
          "kali" => (string) $kali,
          "hari" => (string) $period,
          // location hardcode untuk test
          "location_idIHS" => $config['location_medication_ralan_ihs'],
          "location_nama" => $config['location_medication_ralan_display'],
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_DISPENSE_CREATE),
          payload: $payloadMedication,
        )) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_DISPENSE_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationDispense_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationDispense_idIHS) {
          $MedReqData = $resMedReq['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
            'medicationdispense_idIHS' => $medicationDispense_idIHS, // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid
            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'identifier_noresep' => $MedReqData['identifier_noresep'],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code'   => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm'              => $MedReqData['rm'], // string:7
            'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok'             => $MedReqData['dok'], // string:14
            'requester_idIHS' => $obt['requester_idIHS'], // string:30

            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'] ?? "", // ini tidak ada di medicationDispense
            'expected_supply_duration' => $MedReqData['expected_supply_duration'] ?? "", // harusnya days_supply 

            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'] ?? "", //  fix besok hanya ada di medicationRequest

            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:20
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20

            'when_prepared' => $MedReqData['when_prepared'], // date (y-m-d H:i:s) -> nullable true
            'when_handed_over' => $MedReqData['when_handed_over'], // date (y-m-d H:i:s) -> nullable true

            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status'          => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationDispense OK: " . ($medicationDispense_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq); // kudumen iki hm..
        } else {
          echo " FAILED MedicationDispense";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function 
  }

  /**
   * php yii ssht-api-client/test-send-medication-administration-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationAdministrationRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $medicationPatient = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id',
          'smd.medicationdispense_idIHS'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->leftJoin('ssht_medication_dispense smd', 'smr.medicationrequest_idIHS = smd.medicationdispense_idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationPatient) {
        $this->stdout("[-] Info: $rm pada tanggal $tgl_param tydac ada data MedicationRequest & MedicationDispense\n");
        return;
      }

      print_r($medicationPatient);

      foreach ($medicationPatient as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medication_idIHS" => $getRefObtKfa['medication_idIHS'],
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_ADMINISTRATION_CREATE),
          payload: $payloadMedication,
        )) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_ADMINISTRATION_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationAdministration_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationAdministration_idIHS) {
          $MedReqData = $resMedReq['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_administration', [
            'medicationadministration_idIHS' => $medicationAdministration_idIHS, // uuid
            // 'medicationdispense_idIHS' => $obt['medicationdispense_idIHS'], // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid

            'medication_idIHS' => $MedReqData['medication_idIHS'], // uuid

            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'rm'              => $MedReqData['rm'], // string:7
            'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok'             => $MedReqData['dok'], // string:14

            // iki bagian MedicationRequest.dispenseRequest:
            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            // end bagian MedicationRequest.dispenseRequest:
            // 'dosage_instruction' => $MedReqData['dosage_instruction'] ?? "", // text -> nullable true,

            "reasonCode" => $MedReqData["reasonCode"],

            'status'          => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > medicationAdministration OK: " . ($medicationAdministration_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq);
          // end if $medicationAdministration_idIHS
        } else {
          echo " FAILED medicationAdministration";
          sleep(2);
        }
        sleep(3);
        // endforeach   $medicationPatient
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
  }

  /**
   * php yii ssht-api-client/send-medication-request-ralan 2026-05-01
   */
  public function actionSendMedicationRequestRalan(string $tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $encounter = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'subject_nama',
          'practition_idIHS',
          'practition_nama',
          'practition_lokalid',
          'inprogress_start',
          'inprogress_end',
          'class'
        ])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['class' => 'AMB'])
        ->all($dbLocal);

      if (empty($encounter)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r("Data array encounter:\n");
      print_r($encounter[0]);
      print_r($encounter[1]);
      print_r("...\n");

      foreach ($encounter as $key => $record) {

        $rm = $record['subject_rm'];
        $encounterIdIHS = $record['idIHS'];

        $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

        if (!$simrs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada ProcedureGeneral\n");
          return;
          // continue;
        }

        print_r($simrs);

        foreach ($simrs as $key => $obt) {

          $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

          $payloadMedication = [
            "encounter_idIHS" => $encounterIdIHS,
            "patient_idIHS" => $encounter['subject_idIHS'],
            "patient_nama" => $encounter['subject_nama'],
            "rm" => $encounter['subject_rm'],
            "dok" => $encounter['practition_lokalid'],
            "practition_idIHS" => $encounter['practition_idIHS'],
            "practition_nama" => $encounter['practition_nama'],
            "inprogress_end" => $encounter['inprogress_end'],
            "identifier_noresep" => $identifier_resep->identifier_noresep,
            "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
            "local_id" => (string) $obt['id_local'],
            "kfa_code" => $obt['kfa_code'],
            "kfa_display" => $obt['kfa_display'],
            "kfa_bza" => json_decode($obt['kfa_bza'], true),
            "kfa_form" => json_decode($obt['kfa_form'], true),
            "kfa_route" => json_decode($obt['kfa_route'], true),
            "jumlah" => (string) trim($obt['jumlah']),
            "kali" => (string) trim($obt['kali']),
            "hari" => (string) trim($obt['hari'])
          ];

          //
          // print_r(json_encode($payloadMedication));
          // exit;


          if (!$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
            payload: $payloadMedication,
          )) {
            continue;
          }

          $response = SshtApiBase::request(
            SshtApiUrl::MEDICATION_REQUEST_CREATE,
            [
              'json' => $payloadMedication
            ]
          );

          $resMedReq = json_decode((string) $response->getBody(), true);

          // print_r($resProcReq->getBody());
          $this->stdout("[+] body-response: \n");
          print_r($resMedReq);

          if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
            continue;
          }

          $medicationRequest_idIHS = $resMedReq['data']['medicationRequest_idIHS'] ?? null;
          sleep(1);

          if ($medicationRequest_idIHS) {
            $MedReqData = $resMedReq['data'];

            \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
              'medicationrequest_idIHS' => $medicationRequest_idIHS, // uuid
              'encounter_idIHS' => $encounterIdIHS, // uuid

              'identifier_noresep' => $MedReqData["identifier_noresep"],
              'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

              'contained' => $MedReqData['contained'], // text

              'category_code'   => $MedReqData['category_code'], // string:20
              'category_display' => $MedReqData['category_display'], // string:30
              'category_system' => $MedReqData['category_system'], // string:191

              'rm'              => $MedReqData['rm'], // string:7
              'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30
              'dok'             => $MedReqData['dok'], // string:14
              'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30
              // iki bagian MedicationRequest.dispenseRequest:
              'dispense_interval' => $MedReqData['dispense_interval'], // text
              'expected_supply_duration' => $MedReqData['expected_supply_duration'], // text
              'number_repeat_allowed' => $MedReqData['number_repeat_allowed'], // text

              'performer_org_idIHS' => $MedReqData['performer_org_idIHS'], // string:30 - organisasi farmasi ralan

              'quantity_system' => $MedReqData['quantity_system'], // string:191
              'quantity_code' => $MedReqData['quantity_code'], // string:30
              'quantity_unit' => $MedReqData['quantity_unit'], // string:30
              'quantity_value' => $MedReqData['quantity_value'], // string:20
              'authored_on'   => $MedReqData['authored_on'], // date (y-m-d H:i:s) -> nullable true
              'validity_period_start' => $MedReqData['validity_period_start'], // date (y-m-d H:i:s)
              'validity_period_end' => $MedReqData['validity_period_end'], // date (y-m-d H:i:s)
              // end bagian MedicationRequest.dispenseRequest:
              'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
              'status'          => $MedReqData['status'], // string:30
              'local_id' => $MedReqData['local_id'],

              // untuk mempermudah mapping lokal (trace)
              'petugas_idIHS' => $simrs['ihs_petugas'], // string:30
              'petugas_nama' => $simrs['petugas_nama'], // string:30
              'petugas_ambil_idIHS' => $simrs['ihs_petugas_ambil'], // string:30
              'petugas_ambil_nama' => $simrs['petugas_ambil_nama'], // string:30

              // timestamp
              'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
              'updated_at'      => date('Y-m-d H:i:s') // timestamp nullable true
            ])->execute();

            echo "   > MedicationRequest OK: " . ($medicationRequest_idIHS ?? 'FAILED') . "\n";
            print_r($MedReqData);
          } else {
            echo " FAILED MedicationRequest";
            sleep(3);
          }
          sleep(2);
          //end foreach $encounter record...
        }
        sleep(4);
        // end for each obt 
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function actionTestQueryMedicationRequestRalanSingle
  }


  /**
   * php yii ssht-api-client/send-medication-dispense-ralan 2026-05-01
   */
  public function actionSendMedicationDispenseRalan(string $tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      // payload dari MedicationRequest yang sudah dikirim sebelumnya..
      $medicationRequest = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationRequest) {
        $this->stdout("[-] $tgl_param tydac ada MedicationRequest\n");
        return;
        // continue;
      }

      print_r($medicationRequest);

      foreach ($medicationRequest as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
          // mapping dari ref
          "jumlah" => (string) $obt['quantity_value'],
          "kali" => (string) $kali,
          "hari" => (string) $period,
          // location hardcode untuk test
          "location_idIHS" => $config['location_medication_ralan_ihs'],
          "location_nama" => $config['location_medication_ralan_display'],
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_DISPENSE_CREATE),
          payload: $payloadMedication,
        )) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_DISPENSE_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationDispense_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationDispense_idIHS) {
          $MedReqData = $resMedReq['data'];

          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
            'medicationdispense_idIHS' => $medicationDispense_idIHS, // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid
            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'identifier_noresep' => $MedReqData['identifier_noresep'],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code'   => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm'              => $MedReqData['rm'], // string:7
            'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok'             => $MedReqData['dok'], // string:14
            'requester_idIHS' => $obt['requester_idIHS'], // string:30

            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'] ?? "", // ini tidak ada di medicationDispense
            'expected_supply_duration' => $MedReqData['expected_supply_duration'] ?? "", // harusnya days_supply 

            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'] ?? "", //  fix besok hanya ada di medicationRequest

            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:20
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20

            'when_prepared' => $MedReqData['when_prepared'], // date (y-m-d H:i:s) -> nullable true
            'when_handed_over' => $MedReqData['when_handed_over'], // date (y-m-d H:i:s) -> nullable true

            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status'          => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationDispense OK: " . ($medicationDispense_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq); // kudumen iki hm..
        } else {
          echo " FAILED MedicationDispense";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function 
  }


  /**
   * php yii ssht-api-client/generate-medication-request-ralan-single 2026-05-01 $rm
   */
  public function actionGenerateMedicationRequestRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $encounter = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'subject_nama',
          'practition_idIHS',
          'practition_nama',
          'practition_lokalid',
          'inprogress_start',
          'inprogress_end',
          'class'
        ])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['subject_rm' => $rm])
        ->andWhere(['class' => 'AMB'])
        ->all($dbLocal);

      if (empty($encounter)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r("Data array encounter:\n");
      print_r($encounter[0]);
      // print_r($encounter[1]);
      // print_r("...\n");

      foreach ($encounter as $key => $record) {

        $rm = $record['subject_rm'];
        $encounterIdIHS = $record['idIHS'];

        $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

        if (!$simrs) {
          $this->stdout("[-] SKIP: kunjungan encounter RM: $rm tidak ditemukan data resep obat..\n");
          // return;
          continue;
        }

        print_r($simrs);

        foreach ($simrs as $key => $obt) {

          $identifier_resep = [];

          $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

          print_r($identifier_resep);

          $aturanpakai = SshtApiUtil::genAturanPakaiObat(
            kali: $obt['kali'],
            hari: $obt['hari'],
            sediaan: $obt['sediaan'] ?? "",
            waktu: $obt['waktu'] ?? ""
          );

          $payloadMedication = [
            "encounter_idIHS" => $encounterIdIHS,
            "medication_idIHS" => $obt["medication_idIHS"],
            "patient_idIHS" => $record['subject_idIHS'],
            "patient_nama" => $record['subject_nama'],
            "rm" => $record['subject_rm'],
            "dok" => $record['practition_lokalid'],
            "practition_idIHS" => $record['practition_idIHS'],
            "practition_nama" => $record['practition_nama'],
            "inprogress_end" => $record['inprogress_end'],
            "identifier_noresep" => $identifier_resep->identifier_noresep,
            "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
            "local_id" => (string) $obt['id_local'] ?? "",
            "kfa_code" => $obt['kfa_code'] ?? "",
            "kfa_display" => $obt['kfa_display'] ?? "",
            "kfa_bza" => isset($obt['kfa_bza'])
              ? json_decode($obt['kfa_bza'], true)
              : "",
            "kfa_form" => isset($obt['kfa_form'])
              ? json_decode($obt['kfa_form'], true)
              : "",
            "kfa_route" => isset($obt['kfa_route'])
              ? json_decode($obt['kfa_route'], true)
              : "",
            "jumlah" => (string) trim($obt['jumlah']),
            // "kali" => (string) trim($obt['kali']),
            // "hari" => (string) trim($obt['hari']),
            "aturanpakai" => $aturanpakai,
          ];

          // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
          $checkMedicationRequest = (new Query())
            ->select([
              'id',
              'medicationrequest_idIHS',
              'identifier_noresep',
              'identifier_noresep_index',
            ])
            ->from('ssht_medication_request')
            // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
            ->where(['identifier_noresep_index' => $payloadMedication['identifier_noresep_index']])
            ->one($dbLocal);

          // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
          if (!empty($checkMedicationRequest)) {
            continue;
          }

          if (!$debugger->allow(
            context: ["message" => "test generate task medication Request: " . $payloadMedication['identifier_noresep_index']],
            payload: $payloadMedication,
          )) {
            continue;
          }

          // simpan task record ke db 
          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
            'medicationrequest_idIHS' => '', // uuid
            'medication_idIHS' => $obt['medication_idIHS'] ?? '', // uuid
            'encounter_idIHS' => $encounterIdIHS, // uuid

            'identifier_noresep' => $payloadMedication["identifier_noresep"],
            'identifier_noresep_index' => $payloadMedication["identifier_noresep_index"],

            // 'contained' => '', // text

            // 'category_code'   => '', // string:20
            // 'category_display' => '', // string:30
            // 'category_system' => '', // string:191

            'rm'              => $payloadMedication['rm'], // string:7
            'subject_idIHS'   => $payloadMedication['patient_idIHS'], // string:30
            'dok'             => $payloadMedication['dok'], // string:14
            'requester_idIHS' => $payloadMedication['practition_idIHS'], // string:30
            // iki bagian MedicationRequest.dispenseRequest:
            // 'dispense_interval' => '', // text
            // 'expected_supply_duration' => '', // text
            // 'number_repeat_allowed' => '', // text
            //
            // 'performer_org_idIHS' => '', // string:30 - organisasi farmasi ralan
            //
            // 'quantity_system' => '', // string:191
            // 'quantity_code' => '', // string:30
            // 'quantity_unit' => '', // string:30
            // 'quantity_value' => '', // string:20
            // 'authored_on'   => '', // date (y-m-d H:i:s) -> nullable true
            //
            // 'validity_period_start' => '', // date (y-m-d H:i:s)
            // 'validity_period_end' => '', // date (y-m-d H:i:s)
            // end bagian MedicationRequest.dispenseRequest:
            // 'dosage_instruction' => , // text -> nullable true,
            // 'status'          => $MedReqData['status'], // string:30
            'local_id' => $payloadMedication['local_id'] ?? "",

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $obt['ihs_petugas'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['ihs_petugas_ambil'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            // timestamp
            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s'), // timestamp nullable true

            // addition send
            // 'send_at' => '',
            'send_status' => 'P',
            'payload' => json_encode($payloadMedication),
            // 'send_error_message' => 
            // 'send_error_code' => 

          ])->execute();

          echo "   > MedicationRequest identifier generated: " . ($payloadMedication["identifier_noresep_index"] ?? 'FAILED') . "\n";
          print_r(json_encode($payloadMedication));
        }
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(1);
    }
    // end function actionGenerateMedicationRequestRalan
  }
  // end class console


  /**
   * php yii ssht-api-client/generate-medication-request-ralan 2026-05-01
   * identifier_noresep_index (unique)
   */
  public function actionGenerateMedicationRequestRalan(string $tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_idIHS',
        'practition_nama',
        'practition_lokalid',
        'inprogress_start',
        'inprogress_end',
        'class'
      ])
      ->from('ssht_encounter')
      ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->andWhere(['class' => 'AMB'])
      ->all($dbLocal);

    if (empty($encounter)) {
      $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
      return;
    }

    print_r("Data array encounter:\n");
    print_r($encounter[0]);
    print_r($encounter[1]);
    print_r("...\n");

    foreach ($encounter as $key => $record) {

      $rm = $record['subject_rm'];
      $encounterIdIHS = $record['idIHS'];

      $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

      if (!$simrs) {
        $this->stdout("[-] SKIP: kunjungan encounter RM: $rm tidak ditemukan data resep obat..\n");
        // return;
        continue;
      }

      print_r($simrs);

      foreach ($simrs as $key => $obt) {

        $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

        $aturanpakai = SshtApiUtil::genAturanPakaiObat(
          kali: $obt['kali'],
          hari: $obt['hari'],
          sediaan: $obt['sediaan'] ?? "",
          waktu: $obt['waktu'] ?? ""
        );

        $payloadMedication = [
          "encounter_idIHS" => $encounterIdIHS,
          "medication_idIHS" => (string) $obt['medication_idIHS'],
          "patient_idIHS" => $record['subject_idIHS'],
          "patient_nama" => $record['subject_nama'],
          "rm" => $record['subject_rm'],
          "dok" => $record['practition_lokalid'],
          "practition_idIHS" => $record['practition_idIHS'],
          "practition_nama" => $record['practition_nama'],
          "inprogress_end" => $record['inprogress_end'],
          "identifier_noresep" => $identifier_resep->identifier_noresep,
          "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
          "local_id" => (string) $obt['id_local'] ?? "",
          "kfa_code" => $obt['kfa_code'] ?? "",
          "kfa_display" => $obt['kfa_display'] ?? "",
          "kfa_bza" => isset($obt['kfa_bza'])
            ? json_decode($obt['kfa_bza'], true)
            : "",
          "kfa_form" => isset($obt['kfa_form'])
            ? json_decode($obt['kfa_form'], true)
            : "",
          "kfa_route" => isset($obt['kfa_route'])
            ? json_decode($obt['kfa_route'], true)
            : "",
          "jumlah" => (string) trim($obt['jumlah']),
          "kali" => SshtApiUtil::parseDosisFrekuensiLocal((string) trim($obt['kali'])),
          "hari" => (string) trim($obt['hari']), // dosis harian
          "aturanpakai" => $aturanpakai,
        ];

        // // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
        // $checkMedicationRequest = (new Query())
        //   ->select([
        //     'id',
        //     'medicationrequest_idIHS',
        //     'identifier_noresep',
        //     'identifier_noresep_index',
        //   ])
        //   ->from('ssht_medication_request')
        //   // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        //   ->where(['identifier_noresep_index' => $payloadMedication['identifier_noresep_index']])
        //   ->one($dbLocal);
        //
        // // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
        // if (!empty($checkMedicationRequest)) {
        //   continue;
        // }

        if (!$debugger->allow(
          context: ["message" => "test generate task medication Request: " . $payloadMedication['identifier_noresep_index']],
          payload: $payloadMedication,
        )) {
          continue;
        }

        try {
          // simpan task record ke db 
          \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
            'medicationrequest_idIHS' => '', // uuid
            'medication_idIHS' => $payloadMedication["medication_idIHS"] ?? "", // uuid
            'encounter_idIHS' => $encounterIdIHS, // uuid

            'identifier_noresep' => $payloadMedication["identifier_noresep"],
            'identifier_noresep_index' => $payloadMedication['identifier_noresep_index'],

            // 'contained' => '', // text

            // 'category_code'   => '', // string:20
            // 'category_display' => '', // string:30
            // 'category_system' => '', // string:191

            'rm'              => $payloadMedication['rm'], // string:7
            'subject_idIHS'   => $payloadMedication['patient_idIHS'], // string:30
            'dok'             => $payloadMedication['dok'], // string:14
            'requester_idIHS' => $payloadMedication['practition_idIHS'], // string:30
            // iki bagian MedicationRequest.dispenseRequest:
            // 'dispense_interval' => '', // text
            // 'expected_supply_duration' => '', // text
            // 'number_repeat_allowed' => '', // text
            //
            // 'performer_org_idIHS' => '', // string:30 - organisasi farmasi ralan
            //
            // 'quantity_system' => '', // string:191
            // 'quantity_code' => '', // string:30
            // 'quantity_unit' => '', // string:30
            // 'quantity_value' => '', // string:20
            // 'authored_on'   => '', // date (y-m-d H:i:s) -> nullable true
            //
            // 'validity_period_start' => '', // date (y-m-d H:i:s)
            // 'validity_period_end' => '', // date (y-m-d H:i:s)
            // end bagian MedicationRequest.dispenseRequest:
            // 'dosage_instruction' => , // text -> nullable true,
            // 'status'          => $MedReqData['status'], // string:30
            'local_id' => $payloadMedication['local_id'] ?? "",

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $obt['ihs_petugas'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['ihs_petugas_ambil'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            // timestamp
            'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at'      => date('Y-m-d H:i:s'), // timestamp nullable true

            // addition send
            // 'send_at' => '',
            'send_status' => 'P',
            'payload' => json_encode($payloadMedication),
            // 'send_error_message' => 
            // 'send_error_code' => 

          ])->execute();
        } catch (\yii\db\IntegrityException $e) {
          continue;
        }

        echo "   > MedicationRequest identifier generated: " . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED') . "\n";
        print_r(json_encode($payloadMedication));
      }
    }
    // end function actionGenerateMedicationRequestRalan
  }
  // end class console


  /**
   * php yii ssht-api-client/task-send-medication-request-ralan (--send_status=P)
   * 15 * * * * cron medication ralan
   */
  public function actionTaskSendMedicationRequestRalan()
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $taskMedicationRequest = (new Query())
      ->select([
        "smr.id",
        "smr.medication_idIHS",
        "smr.encounter_idIHS",
        "smr.payload",
        "smr.send_status",
        "smr.created_at",
        "smr.updated_at",
        "smr.send_at",
      ])
      ->from('ssht_medication_request smr')
      ->where(['send_status' => 'P'])
      ->orderBy([
        'id' => SORT_ASC
      ])
      ->limit($config['medication_cron_limit']) // di confing sementara set 100 dulu..
      // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      // ->andWhere(['class' => 'AMB'])
      ->all($dbLocal);

    if (empty($taskMedicationRequest)) {
      $this->stdout("[!] Tydac ada data task medicationRequest \n");
      return;
    }

    foreach ($taskMedicationRequest as $key => $record) {

      try {

        // $rm = $record['rm'];
        // $encounterIdIHS = $record['encounter_idIHS'];

        $payload = json_decode($record['payload'], true);
        // $tglToSend = $payload['inprogress_end'];

        // CHECKING SUDAH TER SYNC MEDICATION blom..
        $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId($payload['local_id']);

        if (!$getLocalObt) {
          $this->stdout("[-] tydac ada local_id tsb..\n");

          // NEED MAPPING kfa + sync medication
          Yii::$app->sshtAPIdb->createCommand()->update(
            'ssht_medication_request',
            [
              'send_status' => 'M',
              'send_error_code' => '',
              'send_error_message' => 'Obat: ' . $payload['local_id'] . ' ,dengan identifier_noresep_index: ' . $payload["identifier_noresep_index"] . ' ,Perlu mapping Obat (KFA & sync Medication) untuk kirim MedicationRequest..',
              // 'payload' => json_encode($payload)
              'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' => $payload["identifier_noresep_index"],
            ]
          )->execute();


          $this->stdout("[-] {$payload['identifier_noresep_index']} dengan Obat: {$payload['local_id']} Perlu dimaping KFA dan Sync Medication.. \n");
          // return;
          continue;
        }

        // $medicationSync = $payload['local_id']

        // SEND TASK to SSHT
        if (!$debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
          payload: $payload,
        )) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_REQUEST_CREATE,
          [
            'json' => $payload
          ]
        );


        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r(json_encode($resMedReq));

        if (
          $response->getStatusCode() == 400 ||
          $response->getStatusCode() == 422 ||
          $response->getStatusCode() == 500 ||
          isset($resMedReq['errors'])
        ) {
          // 1. logic find by identifier_noresep_index 
          // 2. update send_status, send_status_code, send_status_message
          Yii::$app->sshtAPIdb->createCommand()->update(
            'ssht_medication_request',
            [
              'send_status' => 'F',
              'send_error_code' => $response->getStatusCode(),
              'send_error_message' => json_encode($resMedReq['errors']) ?? $response->getMessage(),
              // 'payload' => json_encode($payload)
              'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' => $payload["identifier_noresep_index"],
            ]
          )->execute();
          continue;
        }

        $medicationRequest_idIHS = $resMedReq['data']['medicationRequest_idIHS'] ?? null;
        sleep(1);

        if ($medicationRequest_idIHS) {
          $MedReqData = $resMedReq['data'];
          // else sukses 
          // 1. update record by indentifier_noresep_index
          \Yii::$app->sshtAPIdb->createCommand()->update(
            'ssht_medication_request',
            [
              'medicationrequest_idIHS' => $medicationRequest_idIHS, // uuid
              // 'encounter_idIHS' => $encounterIdIHS, // uuid
              // 'identifier_noresep' => $MedReqData["identifier_noresep"],
              // 'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

              'contained' => json_encode($MedReqData['contained']), // text

              'category_code'   => $MedReqData['category_code'], // string:20
              'category_display' => $MedReqData['category_display'], // string:30
              'category_system' => $MedReqData['category_system'], // string:191

              // 'rm'              => $MedReqData['rm'], // string:7
              // 'subject_idIHS'   => $MedReqData['subject_idIHS'], // string:30
              // 'dok'             => $MedReqData['dok'], // string:14
              // 'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30

              // iki bagian MedicationRequest.dispenseRequest:
              // 'dispense_interval' => isset($MedReqData['dispense_interval'])
              //   ? json_encode($MedReqData['dispense_interval'])
              //   : "",
              // 'expected_supply_duration' => isset($MedReqData['expected_supply_duration'])
              //   ? json_encode($MedReqData['expected_supply_duration']) // text
              //   : "",
              // 'number_repeat_allowed' => $MedReqData['number_repeat_allowed'], // text

              'performer_org_idIHS' => $MedReqData['performer_org_idIHS'], // string:30 - organisasi farmasi ralan

              'quantity_system' => $MedReqData['quantity_system'], // string:191
              'quantity_code' => $MedReqData['quantity_code'], // string:30
              'quantity_unit' => $MedReqData['quantity_unit'], // string:30
              'quantity_value' => $MedReqData['quantity_value'], // string:20
              'authored_on'   => $MedReqData['authored_on'], // date (y-m-d H:i:s) -> nullable true
              'validity_period_start' => $MedReqData['validity_period_start'], // date (y-m-d H:i:s)
              'validity_period_end' => $MedReqData['validity_period_end'], // date (y-m-d H:i:s)
              // end bagian MedicationRequest.dispenseRequest:

              'dosage_instruction' => json_encode($MedReqData['dosage_instruction']), // text -> nullable true,
              'status'          => $MedReqData['status'], // string:30
              'local_id' => $MedReqData['local_id'],

              // untuk mempermudah mapping lokal (trace)
              // 'petugas_idIHS' => $simrs['ihs_petugas'], // string:30
              // 'petugas_nama' => $simrs['petugas_nama'], // string:30
              // 'petugas_ambil_idIHS' => $simrs['ihs_petugas_ambil'], // string:30
              // 'petugas_ambil_nama' => $simrs['petugas_ambil_nama'], // string:30

              // timestamp
              'updated_at' => date('Y-m-d H:i:s'), // timestamp nullable true

              // addition send
              'send_at' => date('Y-m-d H:i:s'),
              'send_status' => 'S',
              'payload' => json_encode($payload),
              'send_error_message' => '',
              'send_error_code' => ''
            ],
            [
              'identifier_noresep_index' => $payload["identifier_noresep_index"],
              // 'updated_at' => date('Y-m-d H:i:s'),
            ]
          )->execute();

          echo "\nMedicationRequest: " . ($medicationRequest_idIHS ?? 'FAILED') . "\n";
          // print_r($payload);
        }
      } catch (\Throwable $e) {
        Yii::$app->sshtAPIdb->createCommand()->update(
          'ssht_medication_request',
          [
            'send_status' => 'F',
            'send_error_code' => $e->getCode(),
            'send_error_message' => $e->getMessage(),
            // 'payload' => json_encode($payload)
            'updated_at' => date('Y-m-d H:i:s'),
          ],
          [
            'identifier_noresep_index' => $payload["identifier_noresep_index"],
          ]
        )->execute();
        continue;
      }
    }
    // end function actionGenerateMedicationRequestRalan
  }


  /**
   * php yii ssht-api-client/generate-medication-dispense-ralan-single 2026-05-01 $rm
   */
  public function actionGenerateMedicationDispenseRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $taskMedicationDispense = (new Query())
        ->select([
          "smr.id",
          "smr.medicationrequest_idIHS",
          "smr.medication_idIHS",
          "smr.encounter_idIHS",
          "smr.payload",
          "smr.rm",
          "smr.dok",
          "smr.send_status",
          "smr.created_at",
          "smr.updated_at",
          "smr.send_at",
          "smr.petugas_idIHS",
          "smr.petugas_nama",
          "smr.petugas_ambil_idIHS",
          "smr.petugas_ambil_nama",
        ])
        ->from('ssht_medication_request smr') // ambil dari medicationRequest yang sukses
        ->where(['smr.send_status' => 'S'])
        ->andWhere(['smr.rm' => $rm])
        // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->limit($config['medication_cron_limit']) // di confing sementara set 100 dulu..
        // ->andWhere(['class' => 'AMB'])
        ->all($dbLocal);

      if (empty($taskMedicationDispense)) {
        $this->stdout("[!] Tydac ada data task medicationRequest \n");
        return;
      }

      foreach ($taskMedicationDispense as $key => $record) {

        $rm = $record['rm'];
        $encounterIdIHS = $record['encounter_idIHS'];
        $payload = json_decode($record['payload'], true);

        $payloadMedication = array_merge($payload, [
          'medicationRequest_idIHS' => $record['medicationrequest_idIHS'],

          'performer_idIHS' => $record['petugas_idIHS'],
          'performer_nama'  => $record['petugas_nama'],

          "location_idIHS" => $config['location_medication_ralan_ihs'],
          "location_nama" => $config['location_medication_ralan_display'],
        ]);

        // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
        $checkMedicationDispense = (new Query())
          ->select([
            'id',
            'medicationdispense_idIHS',
            'identifier_noresep',
            'identifier_noresep_index',
          ])
          ->from('ssht_medication_dispense')
          // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
          ->where(['identifier_noresep_index' => $payloadMedication['identifier_noresep_index']])
          ->one($dbLocal);

        // checking jika sudah ada di ssht_medication_request untuk identifier_noresep_index biar tidak double..
        if (!empty($checkMedicationDispense)) {
          continue;
        }

        if (!$debugger->allow(
          context: ["message" => "test generate task medication Dispense: " . $payloadMedication['identifier_noresep_index']],
          payload: $payloadMedication,
        )) {
          continue;
        }

        // simpan task record ke db 
        \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
          'medicationdispense_idIHS' => '',
          'medication_idIHS' => $payloadMedication['medication_idIHS'] ?? '', // uuid
          'medicationrequest_idIHS' => $payloadMedication['medicationRequest_idIHS'], // uuid
          'encounter_idIHS' => $encounterIdIHS, // uuid

          'identifier_noresep' => $payloadMedication["identifier_noresep"],
          'identifier_noresep_index' => $payloadMedication["identifier_noresep_index"],

          'rm'              => $payloadMedication['rm'], // string:7
          'subject_idIHS'   => $payloadMedication['patient_idIHS'], // string:30
          'dok'             => $payloadMedication['dok'], // string:14
          'requester_idIHS' => $payloadMedication['practition_idIHS'], // string:30
          // iki bagian MedicationRequest.dispenseRequest:
          'local_id' => $payloadMedication['local_id'] ?? "",

          // untuk mempermudah mapping lokal (trace)
          'petugas_idIHS' => $record['petugas_idIHS'], // string:30
          'petugas_nama' => $record['petugas_nama'], // string:30
          'petugas_ambil_idIHS' => $record['petugas_ambil_idIHS'], // string:30
          'petugas_ambil_nama' => $record['petugas_ambil_nama'], // string:30

          // timestamp
          'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
          'updated_at'      => date('Y-m-d H:i:s'), // timestamp nullable true

          // addition send
          // 'send_at' => '',
          'send_status' => 'P',
          'payload' => json_encode($payloadMedication),
          // 'send_error_message' => 
          // 'send_error_code' => 

        ])->execute();

        echo "   > MedicationRequest identifier generated: " . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED') . "\n";
        print_r(json_encode($payloadMedication));
      }
    } catch (\Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(1);
    }
    // end function actionGenerateMedicationDispenseRalanSingle
  }


  /**
   * generateMedicationDispenseTask
   *
   * identifier_noresep_index (unique)
   *
   */
  private function generateMedicationDispenseTask()
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $taskMedicationDispense = (new Query())
      ->select([
        "smr.id",
        "smr.medicationrequest_idIHS",
        "smr.medication_idIHS",
        "smr.encounter_idIHS",
        "smr.identifier_noresep",
        "smr.identifier_noresep_index",
        "smr.payload",
        "smr.rm",
        "smr.dok",
        "smr.send_status",
        "smr.created_at",
        "smr.updated_at",
        "smr.send_at",
        "smr.send_status",
        "smr.petugas_idIHS",
        "smr.petugas_nama",
        "smr.petugas_ambil_idIHS",
        "smr.petugas_ambil_nama",
      ])
      ->from('ssht_medication_request smr') // ambil dari medicationRequest yang sukses
      ->leftJoin(
        'ssht_medication_dispense smd',
        'smd.identifier_noresep_index = smr.identifier_noresep_index'
      )
      ->where(['smr.send_status' => 'S'])
      ->andWhere(['smd.id' => null])
      ->orderBy(['smr.id' => SORT_ASC])
      // ->andWhere(['smr.rm' => $rm])
      // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->limit($config['medication_cron_limit']) // di confing sementara set 100 dulu..
      // ->andWhere(['class' => 'AMB'])
      ->all($dbLocal);

    foreach ($taskMedicationDispense as $key => $record) {

      // $rm = $record['rm'];
      $encounterIdIHS = $record['encounter_idIHS'];
      $payload = json_decode($record['payload'], true);

      $payloadMedication = array_merge($payload, [
        'medicationRequest_idIHS' => $record['medicationrequest_idIHS'],

        'performer_idIHS' => $record['petugas_idIHS'],
        'performer_nama'  => $record['petugas_nama'],

        "location_idIHS" => $config['location_medication_ralan_ihs'],
        "location_nama" => $config['location_medication_ralan_display'],
      ]);

      if (!$debugger->allow(
        context: ["message" => "test generate task medication Dispense: " . $payloadMedication['identifier_noresep_index']],
        payload: $payloadMedication,
      )) {
        continue;
      }

      try {
        // simpan task record ke db 
        \Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
          'medicationdispense_idIHS' => '',
          'medication_idIHS' => $payloadMedication['medication_idIHS'] ?? '', // uuid
          'medicationrequest_idIHS' => $payloadMedication['medicationRequest_idIHS'], // uuid
          'encounter_idIHS' => $encounterIdIHS, // uuid

          'identifier_noresep' => $payloadMedication["identifier_noresep"],
          'identifier_noresep_index' => $payloadMedication["identifier_noresep_index"],

          'rm'              => $payloadMedication['rm'], // string:7
          'subject_idIHS'   => $payloadMedication['patient_idIHS'], // string:30
          'dok'             => $payloadMedication['dok'], // string:14
          'requester_idIHS' => $payloadMedication['practition_idIHS'], // string:30
          // iki bagian MedicationRequest.dispenseRequest:
          'local_id' => $payloadMedication['local_id'] ?? "",

          // untuk mempermudah mapping lokal (trace)
          'petugas_idIHS' => $record['petugas_idIHS'], // string:30
          'petugas_nama' => $record['petugas_nama'], // string:30
          'petugas_ambil_idIHS' => $record['petugas_ambil_idIHS'], // string:30
          'petugas_ambil_nama' => $record['petugas_ambil_nama'], // string:30

          // timestamp
          'created_at'      => date('Y-m-d H:i:s'), //  timestamp nullable true 
          'updated_at'      => date('Y-m-d H:i:s'), // timestamp nullable true

          // addition send
          // 'send_at' => '',
          'send_status' => 'P',
          'payload' => json_encode($payloadMedication),
          // 'send_error_message' => 
          // 'send_error_code' => 

        ])->execute();

        echo "   > MedicationRequest identifier generated: " . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED') . "\n";
        print_r(json_encode($payloadMedication));
      } catch (\yii\db\IntegrityException $e) {
        continue;
      }
    }
  }

  /**
   * php yii ssht-api-client/task-send-medication-dispense-ralan (--send_status=P)
   * 15 * * * * cron medication ralan
   */
  public function actionTaskSendMedicationDispenseRalan()
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    // try {

    $this->generateMedicationDispenseTask();

    $taskMedicationDispense = (new Query())
      ->select([
        "smd.id",
        "smd.medication_idIHS",
        "smd.medicationdispense_idIHS",
        "smd.medicationrequest_idIHS",
        "smd.encounter_idIHS",
        "smd.payload",
        "smd.send_status",
        "smd.created_at",
        "smd.updated_at",
        "smd.send_at",
      ])
      ->from('ssht_medication_dispense smd')
      ->where(['smd.send_status' => 'P'])
      ->orderBy(['smd.id' => SORT_ASC])
      ->limit($config['medication_cron_limit']) // di confing sementara set 100 dulu..
      // ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      // ->andWhere(['class' => 'AMB'])
      ->all($dbLocal);

    if (empty($taskMedicationDispense)) {
      $this->stdout("[!] Tydac ada data task medicationDispense \n");
      return;
    }

    foreach ($taskMedicationDispense as $key => $record) {

      $payload = json_decode($record['payload'], true);
      // $tglToSend = $payload['inprogress_end'];

      // CHECKING SUDAH TER SYNC MEDICATION blom..
      $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId($payload['local_id']);

      if (!$getLocalObt) {
        $this->stdout("[-] tydac ada local_id tsb..\n");

        // NEED MAPPING kfa + sync medication
        Yii::$app->sshtAPIdb->createCommand()->update(
          'ssht_medication_dispense',
          [
            'send_status' => 'M',
            'send_error_code' => '',
            'send_error_message' => 'Obat: ' . $payload['local_id'] . ' ,dengan identifier_noresep_index: ' . $payload["identifier_noresep_index"] . ' ,Perlu mapping Obat (KFA & sync Medication) untuk kirim MedicationDispense..',
            'payload' => json_encode($payload),
            'updated_at' => date('Y-m-d H:i:s'),
          ],
          [
            'identifier_noresep_index' => $payload["identifier_noresep_index"],
          ]
        )->execute();


        $this->stdout("[-] {$payload['identifier_noresep_index']} dengan Obat: {$payload['local_id']} Perlu dimaping KFA dan Sync Medication.. \n");
        // return;
        continue;
      }

      // $medicationSync = $payload['local_id']

      // SEND TASK to SSHT
      if (!$debugger->allow(
        context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_DISPENSE_CREATE),
        payload: $payload,
      )) {
        continue;
      }

      $response = SshtApiBase::request(
        SshtApiUrl::MEDICATION_DISPENSE_CREATE,
        [
          'json' => $payload
        ]
      );


      $resMedReq = json_decode((string) $response->getBody(), true);

      // print_r($resProcReq->getBody());
      $this->stdout("[+] body-response: \n");
      print_r(json_encode($resMedReq));

      if (
        $response->getStatusCode() == 400 ||
        $response->getStatusCode() == 422 ||
        $response->getStatusCode() == 500 ||
        isset($resMedReq['errors'])
      ) {
        // 1. logic find by identifier_noresep_index 
        // 2. update send_status, send_status_code, send_status_message
        Yii::$app->sshtAPIdb->createCommand()->update(
          'ssht_medication_dispense',
          [
            'send_status' => 'F',
            'send_error_code' => $response->getStatusCode(),
            'send_error_message' => json_encode($resMedReq['errors']) ?? $response->getMessage(),
            'payload' => json_encode($payload),
            'updated_at' => date('Y-m-d H:i:s'),
          ],
          [
            'identifier_noresep_index' => $payload["identifier_noresep_index"],
          ]
        )->execute();
        continue;
      }

      $medicationDispense_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
      sleep(1);

      if ($medicationDispense_idIHS) {
        $MedReqData = $resMedReq['data'];
        // else sukses 
        // 1. update record by indentifier_noresep_index
        \Yii::$app->sshtAPIdb->createCommand()->update(
          'ssht_medication_dispense',
          [
            'medicationdispense_idIHS' => $medicationDispense_idIHS, // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid
            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid
            // 'identifier_noresep' => $MedReqData["identifier_noresep"],
            // 'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => json_encode($MedReqData['contained']), // text

            'category_code'   => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            // 'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30 - organisasi farmasi ralan
            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30 - organisasi farmasi ralan

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:30
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20
            // 'authored_on'   => $MedReqData['authored_on'] ?? "", // date (y-m-d H:i:s) -> nullable true
            'when_prepared' => $MedReqData['when_prepared'], // date (y-m-d H:i:s)
            'when_handed_over' => $MedReqData['when_handed_over'], // date (y-m-d H:i:s)

            'dosage_instruction' => json_encode($MedReqData['dosage_instruction']), // text -> nullable true,
            'status'          => $MedReqData['status'], // string:30
            'local_id' => $MedReqData['local_id'],

            // timestamp
            'updated_at' => date('Y-m-d H:i:s'), // timestamp nullable true

            // addition send
            'send_at' => date('Y-m-d H:i:s'),
            'send_status' => 'S',
            'payload' => json_encode($payload),
            'send_error_message' => '',
            'send_error_code' => ''
          ],
          [
            'identifier_noresep_index' => $payload["identifier_noresep_index"],
            // 'updated_at' => date('Y-m-d H:i:s'),
          ]
        )->execute();

        echo "\nMedicationDispense: " . ($medicationDispense_idIHS ?? 'FAILED') . "\n";
        // print_r($payload);
      }
    }
    // } catch (\Exception $e) {
    //   echo " ERROR: " . $e->getMessage() . "\n";
    //   echo "$e";
    //   sleep(1);
    // }
    // end function actionGenerateMedicationRequestRalan
  }
}
