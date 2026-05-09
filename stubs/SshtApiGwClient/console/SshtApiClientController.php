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
  private function parseIcdCodes($rawCodes)
  {
    if (empty($rawCodes)) return [];

    $mapping = [
      "R50.0" => "R50",
      "A01.0" => "A01",
      "Z00.0" => "Z00",
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

  public function actionSendEncounterRalan($tgl_param)
  {
    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    echo "--- TASK BOT SSHT START: " . date('Y-m-d H:i:s') . " ---\n";

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

    echo "\n--- TASK BOT DONE ---\n";
    // return ExitCode::OK;
  }

  public function actionSendEncounterUgd($tgl_param) {}
  public function actionSendEncounterRanap($tgl_param)
  {
    echo "--- TASK BOT SSHT START: " . date('Y-m-d H:i:s') . " ---\n";
    echo "\n--- TASK BOT DONE ---\n";
  }
  public function actionSendEncounterFinish($tgl_param) {}

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
        $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio($enc['class'], $rm, $tgl_param);

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
    $dbLocal = Yii::$app->sshtAPIdb;
    $dbSimrs = Yii::$app->db;

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
              $resR = SshtApiBase::request(SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_RAD, [
                'json' => [
                  "servicerequest_idIHS" => $srIdIhs,
                  "value" => $impressionText,
                  "noradio" => $noradio
                ]
              ]);

              if ($resR->getStatusCode() == 200 || $resR->getStatusCode() == 201) {
                $resDataR = $resR->getBody()['data'] ?? [];
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
}
