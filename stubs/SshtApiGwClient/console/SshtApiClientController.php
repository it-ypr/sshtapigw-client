<?php

namespace common\services\SshtApiGwClient\console;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;
use yii\console\Controller;
use yii\db\Query;
use yii\helpers\Json;
use Exception;
use Yii;

class SshtApiClientController extends Controller
{

  public function actionSendEncounterRalan($tgl_param) {}
  public function actionSendEncounterUgd($tgl_param) {}
  public function actionSendEncounterRanap($tgl_param) {}
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
    $dbSimrs = Yii::$app->db1; // dbSimrs

    try {
      // 1. Ambil data encounter dari DB Lokal
      $encounters = (new Query())
        ->select(['idIHS', 'subject_rm', 'inprogress_start'])
        ->from('ssht_encounter')
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (empty($encounters)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        // 2. Cari detail order di SIMRS
        $simrs = (new Query())
          ->select([
            'rd_order_poli.*',
            'rd_biodata.noregis',
            'rd_biodata.dnkirim',
            'rd_biodata.dkirim',
            'trim(rd_biodata.dperiksa) as dperiksa',
            'muser.nm_user',
            'muser.idssht',
            'rd_daftar_periksa.loinc'
          ])
          ->from('rd_order_poli')
          ->leftJoin('rd_biodata', 'rd_order_poli.noradio = rd_biodata.noradio')
          ->leftJoin('muser', 'rd_biodata.dperiksa = muser.nik')
          ->leftJoin('rd_daftar_periksa', 'rd_order_poli.kode = rd_daftar_periksa.kode')
          ->where([
            'rd_order_poli.rm' => $rm,
            'rd_order_poli.tanggal' => $tgl_param,
            'rd_order_poli.kode' => ['01201', '01202'], // config sini untuk add/exclude rd_daftar_periksa.kode
            'rd_biodata.noregis' => ''
          ])
          ->one($dbSimrs);

        if (!$simrs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada order radiologi\n");
          continue;
        }

        // Persiapkan Payload sesuai wrapper SshtApiBase
        $payload = [
          "noradio" => $simrs['noradio'],
          "tagging" => $simrs['kode'],
          "loinc" => $simrs['loinc'] ?? "",
          "id" => "", // Kosongkan jika memang generate di gateway
          "category" => "radio",
          "reason" => $simrs['indikasi'] ?: "Permintaan Radiologi",
          "encounter_idIHS" => $enc['idIHS'],
          "dokter" => trim($simrs['dkirim']),
          "rm" => $rm,
          "petugas_idIHS" => $simrs['idssht'] ?? "",
          "petugas_nama" => trim($simrs['nm_user'] ?? "")
        ];

        // --- DEBUG & CONFIRMATION ---
        $this->stdout("\n" . str_repeat("=", 50) . "\n");
        $this->stdout("DEBUG PAYLOAD UNTUK RM: $rm\n");
        $this->stdout(Json::encode($payload, JSON_PRETTY_PRINT) . "\n");
        $this->stdout(str_repeat("=", 50) . "\n");

        $pilihan = $this->prompt("Kirim ke API? [Enter=Ya, s=Skip, q=Quit]", ['default' => 'y']);

        if ($pilihan === 's') {
          $this->stdout("[-] RM $rm diskip.\n");
          continue;
        } elseif ($pilihan === 'q') {
          $this->stdout("[!] Berhenti manual.\n");
          break;
        }

        // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_RAD,
          ['json' => $payload]
        );

        $result = $response->getBody();

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
              'pasien_idIHS' => $data_api['patient_idIHS'] ?? null,
              'petugas_idIHS' => $payload['petugas_idIHS'],
              'petugas_nama' => $payload['petugas_nama'],
              'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
              'dok' => $data_api['dok'] ?? null,
              'date' => $enc['inprogress_start'],
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
    $dbLocal = Yii::$app->db;
    $orthancUrl = "http://your-orthanc-url:8042"; // Sesuaikan URL Orthanc
    $orthancAuth = ['admin', 'password']; // Sesuaikan user:pass Orthanc
    $dicomRouterName = "ROUTER_NAME"; // Sesuaikan nama modality di Orthanc

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

      $client = new \yii\httpclient\Client([
        'baseUrl' => $orthancUrl,
        'requestConfig' => ['auth' => $orthancAuth],
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
          "Level" => "Study",
          "Query" => ["AccessionNumber" => $noradio]
        ], ['Content-Type' => 'application/json'])->send();

        if (!$findRes->isOk || empty($findRes->data)) {
          $this->stdout("  [-] Skip: Study tidak ditemukan di Orthanc untuk ACSN {$noradio}\n");
          continue;
        }

        $studies = $findRes->data;

        foreach ($studies as $study_id) {
          // 3. Modify: Buat versi baru dengan metadata lengkap (PatientID IHS & ACSN Baru)
          $modifyRes = $client->post("/studies/{$study_id}/modify", [
            "Replace" => [
              "AccessionNumber" => $new_acsn,
              "PatientID" => (string)$patient_id_ihs,
            ],
            "Force" => true
          ], ['Content-Type' => 'application/json'])->send();

          if ($modifyRes->isOk) {
            $new_study_id = $modifyRes->data['ID'];
            $this->stdout("  [OK] Metadata modified. New Orthanc ID: {$new_study_id}\n");

            // 4. HAPUS STUDY LAMA
            $client->delete("/studies/{$study_id}")->send();
            $this->stdout("  [OK] Study lama ({$study_id}) telah dihapus dari Orthanc.\n");

            // 5. Kirim ID BARU ke DICOM Router
            $storeRes = $client->post("/modalities/{$dicomRouterName}/store", $new_study_id)->send();

            if ($storeRes->isOk) {
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
    $dbLocal = Yii::$app->db;
    $dbSimrs = Yii::$app->dbSimrs;

    try {
      $this->stdout("[*] Menarik data imaging tanggal: {$tgl_param}...\n");

      // 1. Tarik list Imaging Study dari Gateway via Wrapper Base
      $respImaging = SshtApiBase::request(SshtApiUrl::IMAGINGSTUDY_GET_BYDATE, [
        'query' => ['date' => $tgl_param]
      ]);

      $items = $respImaging->getBody()['data'] ?? [];
      $this->stdout("[*] Menemukan " . count($items) . " data untuk diproses.\n");

      foreach ($items as $master) {
        $imgIdIhs = $master['idIHS'] ?? null;
        if (!$imgIdIhs) continue;

        try {
          // 2. Get Detail Imaging untuk dapat ACSN & ServiceRequest ID
          $respDetail = SshtApiBase::request(SshtApiUrl::IMAGINGSTUDY_GET, [
            'query' => ['id' => $imgIdIhs]
          ]);
          $detailData = $respDetail->getBody()['data'] ?? null;
          if (!$detailData) continue;

          $srIdIhs = $detailData['servicerequest_idIHS'];
          $acsnFull = $detailData['acsn'] ?? "";
          $noradio = strpos($acsnFull, '-') !== false ? explode('-', $acsnFull)[0] : $acsnFull;

          // 3. Query Hasil Expertise di SIMRS
          $row = (new Query())
            ->select(['b.rm', 'h.hasil'])
            ->from('rd_hasil h')
            ->leftJoin('rd_biodata b', 'h.noradio = b.noradio')
            ->where(['h.noradio' => $noradio, 'h.ondelete' => 0])
            ->one($dbSimrs);

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
                $resDataO = $resO->getBody()['data'] ?? [];
                $dbLocal->createCommand()->insert('ssht_observation', [
                  'observation_idIHS' => $resDataO['observation_idIHS'],
                  'encounter_idIHS' => $resDataO['encounter_idIHS'],
                  'subject_idIHS' => $resDataO['subject_idIHS'] ?? $resDataO['subject_idIdIHS'],
                  'obs_code' => $resDataO['obs_code'],
                  'obs_display' => $resDataO['obs_display'],
                  'obs_valueString' => $obsText,
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
