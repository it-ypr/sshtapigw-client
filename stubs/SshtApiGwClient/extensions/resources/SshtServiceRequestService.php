<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use Yii;
use yii\db\Query;
use yii\helpers\Json;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\SshtApiDebugger;

use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\util\SshtApiUtil;

class SshtServiceRequestService
{
  private $dbLocal;
  private $dbSimrs;
  private SshtApiDebugger $debugger;
  private $config;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;
    $this->dbSimrs = Yii::$app->db;

    $this->config = SshtApiBase::getConfig();

    $this->debugger = new SshtApiDebugger(
      enabled: $this->config['debug']
    );
  }

  /**
   * Run Cron:
   * php yii ssht-api-client/send-service-request-radio-ralan 2026-05-01
   */
  public function sendRadioRalan(string $tgl_param): void
  {
    $class = 'AMB';

    echo "--- TASK SSHT ServiceRequest Radio (Ralan): [{$tgl_param}] ---\n";

    try {
      // 1. Ambil data encounter dari DB Lokal
      $encounters = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'practition_idIHS',
          'inprogress_start',
          'class',
        ])
        ->from('ssht_encounter')
        ->where([
          'CAST(inprogress_start AS DATE)' => $tgl_param,
        ])
        ->andWhere([
          'class' => $class,
        ])
        ->all($this->dbLocal);

      if (empty($encounters)) {
        $this->stdout(
          "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
        );

        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        // 2. Cari detail order radiologi di SIMRS
        $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio(
          'AMB',
          $rm,
          $tgl_param
        );

        if (!$simrs) {
          $this->stdout(
            "[-] SKIP: RM {$rm} tydac ada order radiologi\n"
          );

          continue;
        }

        // 3. Guard duplicate ServiceRequest UGD
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

        $duplicateServiceRequestRadioRalan =
          $this->dbLocal->createCommand("
            SELECT idIHS
            FROM ssht_servicerequest
            WHERE patient_idIHS = :subject_idIHS
              AND encounter_idIHS = :encounter_idIHS
              AND petugas_ihs = :petugas_idIHS
              AND dokter_request_idIHS = :dokter_request_idIHS
              AND code = :loinc_code
              AND category_display = 'Imaging'
              AND acsn = :acsn
              AND date >= :hour_start
              AND date < :hour_end
              AND class = 'AMB'
            LIMIT 1
          ")
          ->bindValues([
            ':subject_idIHS' => $enc['subject_idIHS'] ?? null,

            ':encounter_idIHS' => $enc['idIHS'],

            ':petugas_idIHS' => $simrs['petugas_ihs'] ?? null,

            ':dokter_request_idIHS' => $enc['practition_idIHS'] ?? null,

            ':loinc_code' => $simrs['loinc'] ?? null,

            ':acsn' => $simrs['noradio'],

            ':hour_start' => $hourStart,

            ':hour_end' => $hourEnd,
          ])
          ->queryScalar();

        if ($duplicateServiceRequestRadioRalan) {
          $this->stdout(
            " SKIPPED "
              . "(DUPLICATE ServiceRequest Radio - UGD)"
          );

          $this->stdout(
            " [IHS: {$duplicateServiceRequestRadioRalan}]"
          );

          $this->stdout(
            " [{$hourStart}]\n"
          );

          continue;
        }

        // 4. Persiapkan payload
        $payload = [
          'noradio' => $simrs['noradio'],
          'tagging' => $simrs['kode'],
          'loinc' => $simrs['loinc'] ?? '',
          'category' => 'radio',
          'reason' => $simrs['indikasi'] ?? 'Permintaan Radiologi',
          'encounter_idIHS' => $enc['idIHS'],
          'dokter' => trim($simrs['dkirim']),
          'rm' => $rm,
          'petugas_idIHS' => $simrs['petugas_ihs'] ?? '',
          'petugas_nama' => trim($simrs['nm_user'] ?? ''),
        ];

        // 5. Debug
        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::SERVICE_REQUEST_CREATE_RAD
          ),
          payload: $payload,
        )) {
          continue;
        }

        // 6. Kirim ke SATUSEHAT
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_RAD,
          ['json' => $payload]
        );

        $result = json_decode(
          (string) $response->getBody(),
          true
        );

        // 7. Simpan hasil
        if (
          isset($result['status'])
          && (
            $result['status'] == 'true'
            || $result['status'] === true
          )
        ) {
          $data_api = $result['data'] ?? [];

          $sr_id_ihs =
            $data_api['servicerequest_idIHS'] ?? null;

          if ($sr_id_ihs) {
            $this->saveServiceRequest(
              $data_api,
              $payload,
              $enc,
              $rm
            );

            $this->stdout(
              "[OK] RM: {$rm} | SR_ID: {$sr_id_ihs}\n"
            );
          }
        } else {
          $errMsg = $result['error'] ?? 'Unknown Error';

          $this->stdout(
            "[ERR] RM: {$rm} | "
              . Json::encode($errMsg)
              . "\n"
          );
        }
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  /**
   * Run Cron:
   * php yii ssht-api-client/send-service-request-radio-ugd 2026-05-01
   */
  public function sendRadioUgd(string $tgl_param): void
  {
    $class = 'EMER';

    echo "--- TASK SSHT ServiceRequest Radio (UGD): [{$tgl_param}] ---\n";

    try {
      // 1. Ambil data encounter dari DB Lokal
      $encounters = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'practition_idIHS',
          'inprogress_start',
          'class',
        ])
        ->from('ssht_encounter')
        ->where([
          'CAST(inprogress_start AS DATE)' => $tgl_param,
        ])
        ->andWhere([
          'class' => $class,
        ])
        ->all($this->dbLocal);

      if (empty($encounters)) {
        $this->stdout(
          "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
        );

        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        // 2. Cari detail order radiologi di SIMRS
        $simrs = SshtApiQueryMapping::queryServiceRequestSimrsRadio(
          'EMER',
          $rm,
          $tgl_param
        );

        if (!$simrs) {
          $this->stdout(
            "[-] SKIP: RM {$rm} tydac ada order radiologi\n"
          );

          continue;
        }

        // 3. Guard duplicate ServiceRequest UGD
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
          $this->dbLocal->createCommand("
            SELECT idIHS
            FROM ssht_servicerequest
            WHERE patient_idIHS = :subject_idIHS
              AND encounter_idIHS = :encounter_idIHS
              AND petugas_ihs = :petugas_idIHS
              AND dokter_request_idIHS = :dokter_request_idIHS
              AND code = :loinc_code
              AND category_display = 'Imaging'
              AND acsn = :acsn
              AND date >= :hour_start
              AND date < :hour_end
              AND class = 'EMER'
            LIMIT 1
          ")
          ->bindValues([
            ':subject_idIHS' => $enc['subject_idIHS'] ?? null,

            ':encounter_idIHS' => $enc['idIHS'],

            ':petugas_idIHS' => $simrs['petugas_ihs'] ?? null,

            ':dokter_request_idIHS' => $enc['practition_idIHS'] ?? null,

            ':loinc_code' => $simrs['loinc'] ?? null,

            ':acsn' => $simrs['noradio'],

            ':hour_start' => $hourStart,

            ':hour_end' => $hourEnd,
          ])
          ->queryScalar();

        if ($duplicateServiceRequestRadioUgd) {
          $this->stdout(
            " SKIPPED "
              . "(DUPLICATE ServiceRequest Radio - UGD)"
          );

          $this->stdout(
            " [IHS: {$duplicateServiceRequestRadioUgd}]"
          );

          $this->stdout(
            " [{$hourStart}]\n"
          );

          continue;
        }

        // 4. Persiapkan payload
        $payload = [
          'noradio' => $simrs['noradio'],
          'tagging' => $simrs['kode'],
          'loinc' => $simrs['loinc'] ?? '',
          'category' => 'radio',
          'reason' => $simrs['indikasi']
            ?? 'Permintaan Radiologi',
          'encounter_idIHS' => $enc['idIHS'],
          'dokter' => trim($simrs['dkirim']),
          'rm' => $rm,
          'petugas_idIHS' => $simrs['petugas_ihs'] ?? '',
          'petugas_nama' => trim($simrs['nm_user'] ?? ''),
        ];

        // 5. Debug
        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::SERVICE_REQUEST_CREATE_RAD
          ),
          payload: $payload,
        )) {
          continue;
        }

        // 6. Kirim ke SATUSEHAT
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_RAD,
          ['json' => $payload]
        );

        $result = json_decode(
          (string) $response->getBody(),
          true
        );

        // 7. Simpan hasil
        if (
          isset($result['status'])
          && (
            $result['status'] == 'true'
            || $result['status'] === true
          )
        ) {
          $data_api = $result['data'] ?? [];

          $sr_id_ihs =
            $data_api['servicerequest_idIHS'] ?? null;

          if ($sr_id_ihs) {
            $this->saveServiceRequest(
              $data_api,
              $payload,
              $enc,
              $rm
            );

            $this->stdout(
              "[OK] RM: {$rm} | SR_ID: {$sr_id_ihs}\n"
            );
          }
        } else {
          $errMsg = $result['error'] ?? 'Unknown Error';

          $this->stdout(
            "[ERR] RM: {$rm} | "
              . Json::encode($errMsg)
              . "\n"
          );
        }
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  public function sendLabRalan(string $tgl_param): void
  {
    echo "--- TASK SSHT ServiceRequest & Specimen Lab (Ralan): [{$tgl_param}] ---\n";

    $encounters = $this->getLabEncounters(
      tgl_param: $tgl_param,
      encounterClass: 'AMB'
    );

    if (empty($encounters)) {
      $this->stdout(
        "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
      );
      return;
    }

    foreach ($encounters as $enc) {
      $this->sendLabForEncounter(
        tgl_param: $tgl_param,
        enc: $enc
      );
    }
  }

  public function sendLabUgd(string $tgl_param): void
  {
    echo "--- TASK SSHT ServiceRequest & Specimen Lab (UGD): [{$tgl_param}] ---\n";

    $encounters = $this->getLabEncounters(
      tgl_param: $tgl_param,
      encounterClass: 'EMER'
    );

    if (empty($encounters)) {
      $this->stdout(
        "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
      );
      return;
    }

    foreach ($encounters as $enc) {
      $this->sendLabForEncounter(
        tgl_param: $tgl_param,
        enc: $enc
      );
    }
  }

  private function getLabEncounters(
    string $tgl_param,
    string $encounterClass
  ): array {
    return (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'practition_lokalid',
        'inprogress_start',
        'inprogress_end',
        'class',
      ])
      ->from('ssht_encounter')
      ->where([
        'CAST(inprogress_start AS DATE)' => $tgl_param,
        'class' => $encounterClass,
      ])
      ->all($this->dbLocal);
  }

  private function sendLabForEncounter(
    string $tgl_param,
    array $enc
  ): void {
    $rm = $enc['subject_rm'];

    $dataLabRalan = SshtApiQueryMapping::queryLabRalan(
      $tgl_param,
      $rm
    );

    if (!$dataLabRalan || empty($dataLabRalan)) {
      $this->stdout(
        "[-] SKIP: RM {$rm} tydac ada order Lab\n"
      );
      return;
    }

    $dataPreReqLab = $dataLabRalan['prereq'];

    foreach ($dataLabRalan['lab_result'] as $lab) {
      $payload = [
        'sampleID' =>
        $lab['svc_req']['service_req_code'],

        'category' =>
        'lab',

        'serviceReqCode' =>
        $lab['svc_req']['loinc_order_code'],

        'serviceReqDisplay' =>
        $lab['svc_req']['loinc_order_display'],

        'reason' =>
        $lab['svc_req']['nama_lokal'],

        'encounter_idIHS' =>
        $enc['idIHS'],

        'dokter' =>
        $enc['practition_lokalid'],

        'rm' =>
        $enc['subject_rm'],

        'petugaslab_idIHS' =>
        $dataPreReqLab['petugas_ihs'],

        'petugaslab_nama' =>
        $dataPreReqLab['petugas_nama'],
      ];

      if (
        !$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::SERVICE_REQUEST_CREATE_LAB
          ),
          payload: $payload,
        )
      ) {
        continue;
      }

      $response = SshtApiBase::request(
        SshtApiUrl::SERVICE_REQUEST_CREATE_LAB,
        [
          'json' => $payload
        ]
      );

      $result = json_decode(
        (string) $response->getBody(),
        true
      );

      print_r(json_encode($result));

      if (
        !isset($result['status']) ||
        $result['status'] != 'true'
      ) {
        $this->stdout(
          "[-] Failed ServiceRequest " .
            json_encode($payload) .
            ", Rm: {$rm}\n"
        );
        continue;
      }

      $dataApi = $result['data'] ?? [];

      $srIdIhs =
        $dataApi['servicerequest_idIHS'] ?? null;

      $now = date('Y-m-d H:i:s');

      $this->dbLocal
        ->createCommand()
        ->insert('ssht_servicerequest', [
          'servicerequest_idIHS' =>
          $srIdIhs,

          'encounter_idIHS' =>
          $enc['idIHS'],

          'acsn' =>
          $dataApi['acsn'] ?? null,

          'category_code' =>
          $dataApi['category_code'] ?? null,

          'category_display' =>
          $dataApi['category_display'] ?? null,

          'code' =>
          $dataApi['code'] ?? null,

          'display' =>
          $dataApi['display'] ?? null,

          'perihal' =>
          $dataApi['perihal'] ?? null,

          'rm' =>
          $rm,

          'patient_idIHS' =>
          $dataApi['patient_idIHS'] ?? null,

          'petugas_idIHS' =>
          $payload['petugaslab_idIHS'],

          'petugas_nama' =>
          $payload['petugaslab_nama'],

          'dokter_request_idIHS' =>
          $dataApi['dokter_request_idIHS'] ?? null,

          'dok' =>
          $dataApi['dok'] ?? null,

          'date' =>
          $dataApi['date'],

          'status' =>
          'active',

          'created_at' =>
          $now,

          'updated_at' =>
          $now,

          'srid' =>
          $dataApi['srid'],
        ])
        ->execute();

      $this->stdout(
        "[+] Sukses ServiceRequest Lab: " .
          json_encode($payload) .
          ", Rm: {$rm}, " .
          "ServiceRequest: {$srIdIhs}\n"
      );

      $payloadSpeciment = [
        'sampleID' =>
        $lab['specimen']['sample_id'],

        'servicerequest_idIHS' =>
        $srIdIhs,

        'encounter_idIHS' =>
        $enc['idIHS'],

        'speciment_code' =>
        $lab['specimen']['specimen_code'],

        'speciment_display' =>
        $lab['specimen']['specimen_display'],

        'sampling_method' =>
        $lab['specimen']['sampling_method'],

        'rm' =>
        $rm,

        'dok' =>
        $enc['practition_lokalid'],
      ];

      (new SshtSpecimenService())
        ->sendRalanSingle(
          rm: $rm,
          enc: $enc,
          payload: $payload,
          payloadSpeciment: $payloadSpeciment,
        );
    }
  }

  private function saveServiceRequest(
    array $data_api,
    array $payload,
    array $enc,
    string $rm
  ): void {
    $now = date('Y-m-d H:i:s');

    $this->dbLocal
      ->createCommand()
      ->insert('ssht_servicerequest', [
        'servicerequest_idIHS' =>
        $data_api['servicerequest_idIHS'] ?? null,

        'encounter_idIHS' =>
        $enc['idIHS'],

        'acsn' =>
        $data_api['acsn'] ?? null,

        'category_code' =>
        $data_api['category_code'] ?? null,

        'category_display' =>
        $data_api['category_display'] ?? null,

        'code' =>
        $data_api['code'] ?? null,

        'display' =>
        $data_api['display'] ?? null,

        'perihal' =>
        $data_api['perihal'] ?? null,

        'rm' =>
        $rm,

        'patient_idIHS' =>
        $data_api['patient_idIHS'] ?? null,

        'petugas_idIHS' =>
        $payload['petugas_idIHS'],

        'petugas_nama' =>
        $payload['petugas_nama'],

        'dokter_request_idIHS' =>
        $data_api['dokter_request_idIHS'] ?? null,

        'dok' =>
        $data_api['dok'] ?? null,

        'date' =>
        $data_api['date'] ?? null,

        'status' =>
        'active',

        'created_at' =>
        $now,

        'updated_at' =>
        $now,

        'srid' =>
        $data_api['srid'] ?? null,
      ])
      ->execute();
  }

  private function stdout(string $message): void
  {
    echo $message;
  }
}
