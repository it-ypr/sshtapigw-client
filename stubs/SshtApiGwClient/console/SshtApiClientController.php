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
  private $config;

  public function __construct()
  {
    $this->config = Yii::$app->params['SSHTApiConfig'] ?? [];
  }

  private function fetchDataSimrs($date)
  {
    // Mereplikasi logic subquery Laravel kamu
    $subQuery = (new Query())
      ->select(['Id', 'trim(rm) as rm', 'tanggal', 'trim(dokter) as dokter', 'poli', 'tglperiksa', 'resep'])
      ->from('mr_periksa_poli')
      ->where(['like', 'tanggal', $date])
      ->groupBy('Id');

    return (new Query())
      ->select([
        'tblnew.rm',
        'TRIM(mr_ktp.ktp) as ktp',
        'TRIM(mmr.nama) as pasien_nama',
        'TRIM(muser.nm_user) as dokter_nama',
        'muser.idssht as dokter_ihs',
        'mpoli.idihs as lokasi_ihs',
        "GROUP_CONCAT(DISTINCT RTRIM(mr_kunjungan.icd) SEPARATOR ';') as icd_codes",
        'MIN(tblnew.tglperiksa) as a_end',
        'MAX(tblnew.resep) as p_rawend'
      ])
      ->from(['tblnew' => $subQuery])
      ->innerJoin('mr_kunjungan', 'tblnew.rm = mr_kunjungan.rm')
      ->innerJoin('mpoli', 'tblnew.poli = mpoli.poli')
      ->innerJoin('mmr', 'tblnew.rm = mmr.rm')
      ->innerJoin('mr_ktp', 'tblnew.rm = mr_ktp.rm')
      ->innerJoin('muser', 'tblnew.dokter = muser.id_user')
      ->where(['like', 'tblnew.tglperiksa', $date . '%'])
      ->andWhere(['mr_kunjungan.tlanjut' => 'PULANG'])
      ->andWhere(['not in', 'tblnew.poli', ['11', '09', '98', '87', '74']]) // Filter poli
      ->andWhere(['not', ['muser.idssht' => null]])
      ->groupBy('tblnew.rm')
      ->all(\Yii::$app->db1); // Asumsi db2 adalah koneksi SIMRS
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
    $result = [];

    foreach ($codes as $c) {
      $clean = preg_replace('/[^.;a-zA-Z0-9]/', '', $c);
      if (!$clean) continue;

      // Cek apakah ada di dictionary mapping
      if (isset($mapping[$clean])) {
        $clean = $mapping[$clean];
      }

      $result[] = [
        'code' => $clean,
        'display' => $this->getIcdDisplay($clean)
      ];
    }

    // Filter kode double (Unique)
    return array_values(array_column($result, null, 'code'));
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

  private function getIcdDisplay($code)
  {
    // Dummy display, bisa diganti query ke table master ICD10
    return "Diagnosis " . $code;
  }

  public function actionSendEncounterRalan($tgl_param)
  {
    echo "--- TASK BOT SSHT START: " . date('Y-m-d H:i:s') . " ---\n";

    // 1. Ambil data dari SIMRS (Repolusi dari Laravel Query)
    $dataEncounter = $this->fetchDataSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      return ExitCode::OK;
    }

    foreach ($dataEncounter as $row) {
      try {
        echo "\nProcessing RM: {$row['rm']}...";

        // 2. Get IHS Pasien via KTP (Wrapper)
        $ktp = preg_replace('/\D/', '', $row['ktp']);
        $resPatient = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_BY_NIK, ['query' => ['id' => $ktp]]);

        sleep(1);

        $pasienIhs = $resPatient['data']['idIHS'] ?? null;

        if (!$pasienIhs) {
          echo " SKIPPED (IHS Pasien tidak ditemukan)";
          continue;
        }

        // 3. Get Nama Lokasi (Wrapper)
        $resLocation = SshtApiBase::request(SshtApiUrl::LOCATION_GET_BY_IHS, ['query' => ['id' => $row['lokasi_ihs']]]);
        $locationNama = $resLocation['name'] ?? 'Poliklinik';

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

        $resEnc = SshtApiBase::request(SshtApiUrl::ENCOUNTER_CREATE, ['json' => $payloadEncounter]);
        $encounterIhsId = $resEnc['idIHS'] ?? null;

        sleep(1);

        if ($encounterIhsId) {
          echo " SUCCESS ENCOUNTER: $encounterIhsId\n";

          // 6. SEND CONDITION (Looping ICD10)
          $icdList = $this->parseIcdCodes($row['icd_codes']);

          foreach ($icdList as $icd) {
            $payloadCondition = [
              "encounter_idIHS" => $encounterIhsId,
              "patient_idIHS" => $pasienIhs,
              "patient_nama" => $row['pasien_nama'],
              "conditionCode" => $icd['code'],
              "conditionName" => $icd['display'],
              "inprogress_start" => $times['inprogress_at'],
              "inprogress_end" => $times['inprogress_end']
            ];

            $resCond = SshtApiBase::request(SshtApiUrl::CONDITION_CREATE, ['json' => $payloadCondition]);
            sleep(2);
            echo "   > Condition OK: " . ($resCond['idIHS'] ?? 'FAILED') . " ({$icd['code']})\n";
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
    return ExitCode::OK;
  }

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
    $orthancUrl = $this->config["orthanc_url"]; // Sesuaikan URL Orthanc
    $orthancAuth = [$this->config["orthanc_auth_user"], $this->config["orthanc_auth_password"]]; // Sesuaikan user:pass Orthanc
    $dicomRouterName = $this->config["dicom_router_name"]; // Sesuaikan nama modality di Orthanc

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
    $dbLocal = Yii::$app->sshtAPIdb;
    $dbSimrs = Yii::$app->db1;

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
