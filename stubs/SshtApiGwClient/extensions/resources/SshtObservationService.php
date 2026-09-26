<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use Yii;
use yii\db\Query;
use yii\helpers\Json;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;
use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;

class SshtObservationService
{
  private $dbLocal;
  private SshtApiDebugger $debugger;
  private array $config;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;

    $this->config = SshtApiBase::getConfig();

    $this->debugger = new SshtApiDebugger(
      enabled: $this->config['debug'] ?? false
    );
  }

  public function sendRalan(string $tgl_param): void
  {
    $class = 'AMB';

    try {
      $encounters = $this->getVitalEncounters(
        tgl_param: $tgl_param,
        encounterClass: $class
      );

      if (empty($encounters)) {
        $this->stdout(
          "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        $simrsobs =
          SshtApiQueryMapping::queryObservationRalan(
            $tgl_param,
            $rm
          );

        if (!$simrsobs) {
          $this->stdout(
            "[-] SKIP: RM {$rm} tydac ada data observasi vital\n"
          );
          continue;
        }

        $this->sendVitalObservations(
          enc: $enc,
          simrsobs: $simrsobs
        );
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  public function sendUgd(string $tgl_param): void
  {
    $class = 'EMER';

    try {
      $encounters = $this->getVitalEncounters(
        tgl_param: $tgl_param,
        encounterClass: $class
      );

      if (empty($encounters)) {
        $this->stdout(
          "[!] Tydac ada data encounter tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($encounters as $enc) {
        $rm = $enc['subject_rm'];

        $simrsobs =
          SshtApiQueryMapping::queryObservationUgd(
            $tgl_param,
            $rm
          );

        if (!$simrsobs) {
          $this->stdout(
            "[-] SKIP: RM {$rm} tydac ada data observasi vital\n"
          );
          continue;
        }

        $this->sendVitalObservations(
          enc: $enc,
          simrsobs: $simrsobs
        );
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  public function createRadio(
    string $servicerequestIdIhs,
    string $imagingstudyIdIhs,
    string $rm,
    string $valueString
  ): ?string {
    $payload = [
      'servicerequest_idIHS' => $servicerequestIdIhs,
      'imagingstudy_idIHS' => $imagingstudyIdIhs,
      'rm' => $rm,
      'valueString' => $valueString,
    ];

    if (!$this->debugger->allow(
      context: SshtApiUtil::genDebugContext(
        SshtApiUrl::OBSERVATION_CREATE_RAD
      ),
      payload: $payload,
    )) {
      return null;
    }

    $response = SshtApiBase::request(
      SshtApiUrl::OBSERVATION_CREATE_RAD,
      [
        'json' => $payload
      ]
    );

    // $statusCode = $response['statusCode'] ?? null;
    $statusCode = $response->getStatusCode() ?? null;

    if (!in_array($statusCode, [200, 201], true)) {
      return null;
    }

    $respReq = json_decode(
      $response->getBody()->getContents(),
      true
    );

    $resData = $respReq['data'] ?? [];

    if (empty($resData)) {
      return null;
    }

    print_r($resData);

    $observationIdIhs =
      $resData['observation_idIHS'] ?? null;

    if (!$observationIdIhs) {
      return null;
    }

    $now = date('Y-m-d H:i:s');

    $this->dbLocal
      ->createCommand()
      ->insert('ssht_observation', [
        'observation_idIHS' => $observationIdIhs,
        'encounter_idIHS' => $resData['encounter_idIHS'] ?? null,
        'subject_idIHS' => $resData['subject_idIHS']
          ?? $resData['subject_idIdIHS']
          ?? null,

        'obs_system' => $resData['obs_system'] ?? null,
        'obs_code' => $resData['obs_code'] ?? null,
        'obs_display' => $resData['obs_display'] ?? null,

        'obs_valueString' => $resData['obs_valueString'] ?? null,

        'date' => $resData['date'] ?? null,

        'rm' => $rm,
        'status' => $resData['status'] ?? null,
        'created_at' => $now,

        'category_system' => $resData['category_system'] ?? null,
        'category_code' => $resData['category_code'] ?? null,
        'category_display' => $resData['category_display'] ?? null,

        'performer_idIHS' => $dataApi['performer'] ?? null,
      ])
      ->execute();

    return $observationIdIhs;
  }

  public function existsRadio(
    string $rm,
    string $tgl_param,
    string $valueString
  ): bool {
    return (new Query())
      ->from('ssht_observation')
      ->where([
        'rm' => $rm,
        'category_code' => 'imaging',
        'obs_valueString' => $valueString,
      ])
      ->andWhere([
        'like',
        'date',
        $tgl_param
      ])
      ->exists($this->dbLocal);
  }

  public function sendLabRalan(string $tgl_param): void
  {
    echo "--- TASK SSHT Observation Lab (Ralan): [{$tgl_param}] ---\n";

    try {
      $serviceRequestLab = $this->getLabServiceRequests(
        tgl_param: $tgl_param,
        encounterClass: 'AMB'
      );

      if (empty($serviceRequestLab)) {
        $this->stdout(
          "[!] Tydac ada data order serviceRequestLab " .
            "tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($serviceRequestLab as $srlab) {
        $srrm = $srlab['rm'];

        if (
          empty($srlab['servicerequest_idIHS']) ||
          empty($srlab['speciment_idIHS'])
        ) {
          $this->stdout(
            "[!] Skip anomali ServiceRequest/Specimen: " .
              "SR: " .
              ($srlab['servicerequest_idIHS'] ?? '-') .
              ", Specimen: " .
              ($srlab['speciment_idIHS'] ?? '-') .
              ", Encounter: " .
              ($srlab['encounter_idIHS'] ?? '-') .
              ", RM: {$srrm}, tanggal: {$tgl_param}\n"
          );

          continue;
        }

        $obsLabs =
          SshtApiQueryMapping::getObservationLabLocalRalan(
            $tgl_param,
            $srrm,
            $srlab['sr_code']
          );

        if (!$obsLabs) {
          $this->stdout(
            "[!] Skip: tydac ditemukan data Hasil " .
              "Observasi lab Rm: {$srrm}, " .
              "tanggal {$tgl_param}\n"
          );
          continue;
        }

        $this->sendLabObservations(
          tgl_param: $tgl_param,
          srlab: $srlab,
          obsLabs: $obsLabs
        );
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  public function sendLabUgd(string $tgl_param): void
  {
    echo "--- TASK SSHT Observation Lab (UGD): [{$tgl_param}] ---\n";

    try {
      $serviceRequestLab = $this->getLabServiceRequests(
        tgl_param: $tgl_param,
        encounterClass: 'EMER'
      );

      if (empty($serviceRequestLab)) {
        $this->stdout(
          "[!] Tydac ada data order serviceRequestLab " .
            "tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($serviceRequestLab as $srlab) {
        $srrm = $srlab['rm'];

        if (
          empty($srlab['servicerequest_idIHS']) ||
          empty($srlab['speciment_idIHS'])
        ) {
          $this->stdout(
            "[!] Skip anomali ServiceRequest/Specimen: " .
              "SR: " .
              ($srlab['servicerequest_idIHS'] ?? '-') .
              ", Specimen: " .
              ($srlab['speciment_idIHS'] ?? '-') .
              ", Encounter: " .
              ($srlab['encounter_idIHS'] ?? '-') .
              ", RM: {$srrm}, tanggal: {$tgl_param}\n"
          );

          continue;
        }

        // NOTE:
        // Sesuai logic existing, UGD tetap menggunakan
        // queryObservationLabLocalRalan().
        $obsLabs =
          SshtApiQueryMapping::getObservationLabLocalRalan(
            $tgl_param,
            $srrm,
            $srlab['sr_code']
          );

        if (!$obsLabs) {
          $this->stdout(
            "[!] Skip: tydac ditemukan data Hasil " .
              "Observasi lab Rm: {$srrm}, " .
              "tanggal {$tgl_param}\n"
          );
          continue;
        }

        $this->sendLabObservations(
          tgl_param: $tgl_param,
          srlab: $srlab,
          obsLabs: $obsLabs
        );
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] " . $e->getMessage() . "\n"
      );
    }
  }

  public function sendLabRalanSingle(
    string $tgl_param,
    string $rm
  ): void {
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
      ->leftJoin(
        'ssht_speciment ssp',
        'ssr.servicerequest_idIHS = ssp.servicerequest_idIHS'
      )
      ->where([
        'CAST(ssr.date AS DATE)' => $tgl_param,
        'ssr.rm' => $rm,
        'ssr.status' => 'active',
        'ssr.category_code' => '108252007',
        'ssr.category_display' => 'Laboratory procedure',
      ])
      ->all($this->dbLocal);

    if (empty($serviceRequestLab)) {
      $this->stdout(
        "[!] Tydac ada data order serviceRequestLab " .
          "tanggal {$tgl_param}, RM: {$rm}\n"
      );
      return;
    }

    foreach ($serviceRequestLab as $srlab) {
      $srrm = $srlab['rm'];

      $obsLabs = SshtApiQueryMapping::getObservationLabLocalRalan(
        $tgl_param,
        $srrm,
        $srlab['sr_code']
      );

      if (!$obsLabs) {
        $this->stdout(
          "[!] Skip: tydac ditemukan data Hasil Observasi lab " .
            "Rm: {$srrm}, tanggal {$tgl_param}\n"
        );
        continue;
      }

      $this->sendLabObservations(
        tgl_param: $tgl_param,
        srlab: $srlab,
        obsLabs: $obsLabs
      );
    }
  }

  private function getLabServiceRequests(
    string $tgl_param,
    string $encounterClass
  ): array {
    return (new Query())
      ->select([
        'ssr.servicerequest_idIHS',
        'sse.idIHS as encounter_idIHS',
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
      ->leftJoin(
        'ssht_speciment ssp',
        'ssr.servicerequest_idIHS = ssp.servicerequest_idIHS'
      )
      ->leftJoin(
        'ssht_encounter sse',
        'ssr.servicerequest_idIHS = sse.idIHS'
      )
      ->where([
        'CAST(ssr.date AS DATE)' => $tgl_param,
        'ssr.status' => 'active',
        'ssr.category_code' => '108252007',
        'ssr.category_display' => 'Laboratory procedure',
        'sse.class' => $encounterClass,
      ])
      ->all($this->dbLocal);
  }

  private function sendLabObservations(
    string $tgl_param,
    array $srlab,
    array $obsLabs
  ): void {
    $srrm = $srlab['rm'];

    foreach ($obsLabs as $obsLab) {
      $labloinc = (new Query())
        ->select([
          'code',
          'display',
          'tipe_hasil_pemeriksaan',
          'scale',
          'unit_of_measure',
          'code_system',
        ])
        ->from('loinc_lab')
        ->where([
          'code' => $obsLab['loinc_code']
        ])
        ->one(Yii::$app->dbsshtterminologi);

      if (empty($labloinc)) {
        $this->stdout(
          "[!] Skip: tydac ditemukan kode loinc untuk " .
            "data Hasil Observasi lab dengan kode lokal: " .
            "{$obsLab['TEST_ID']}, " .
            "Rm: {$srrm}, tanggal {$tgl_param}\n"
        );
        continue;
      }

      $range = array_map(
        'trim',
        explode('-', $obsLab['referenceRange'], 2)
      );

      $referenceRange =
        isset($range[1], $range[0]) &&
        $range[0] !== '' &&
        $range[1] !== ''
        ? $range
        : $obsLab['ANGKA_NORMAL'];

      $payloadObs = [
        'servicerequest_idIHS' =>
        $srlab['servicerequest_idIHS'],

        'speciment_idIHS' =>
        $srlab['speciment_idIHS'],

        'sampelID_TestID' =>
        $obsLab['SAMPEL_ID'] . '-' . $obsLab['TEST_ID'],

        'rm' => $srrm,

        'laborat' =>
        $srlab['petugas_idIHS'],

        'scale' =>
        $labloinc['scale'] === '-'
          ? $labloinc['tipe_hasil_pemeriksaan']
          : $labloinc['scale'],

        'code-obs' =>
        $labloinc['code'],

        'code-display' =>
        $labloinc['display'],

        'referenceRange' =>
        $referenceRange,

        'unit-referenceRange' =>
        $labloinc['unit_of_measure'],

        'valueQuantity' =>
        $obsLab['RESULT_VALUE'],

        'unit-valueQuantity' =>
        $labloinc['unit_of_measure'],
      ];

      $checkObsLokal = (new Query())
        ->from('ssht_observation sso')
        ->where([
          'sso.encounter_idIHS' =>
          $srlab['encounter_idIHS'],

          'rm' => $srrm,

          'status' => 'active',

          'obs_code' =>
          $payloadObs['code-obs'],
        ])
        ->exists($this->dbLocal);

      if ($checkObsLokal) {
        $this->stdout(
          "[!] Skip: duplicate data Hasil Observasi lab " .
            "dengan kode lokal: {$obsLab['TEST_ID']}, " .
            "Rm: {$srrm}, tanggal {$tgl_param}\n"
        );

        continue;
      }

      if (!$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::OBSERVATION_CREATE_LAB
        ),
        payload: $payloadObs,
      )) {
        continue;
      }

      $response = SshtApiBase::request(
        SshtApiUrl::OBSERVATION_CREATE_LAB,
        [
          'json' => $payloadObs
        ]
      );

      $result = json_decode(
        (string) $response->getBody(),
        true
      );

      print_r(json_encode($result));

      if (
        isset($result['status']) &&
        $result['status'] == 'true'
      ) {
        $dataApi = $result['data'] ?? [];

        $observationIdIhs =
          $dataApi['observation_idIHS'] ?? null;

        if (!$observationIdIhs) {
          $this->stdout(
            "  [Failed] Response Observation Lab " .
              "tidak memiliki observation_idIHS\n"
          );
          continue;
        }

        $this->dbLocal
          ->createCommand()
          ->insert('ssht_observation', [
            'observation_idIHS' =>
            $dataApi['observation_idIHS'],

            'encounter_idIHS' =>
            $dataApi['encounter_idIHS'],

            'subject_idIHS' =>
            $dataApi['subject_idIHS'],

            'obs_code' =>
            $dataApi['obs_code'],

            'obs_display' =>
            $dataApi['obs_display'],

            'obs_value' =>
            $dataApi['obs_value'] ?? '',

            'obs_valueString' =>
            $dataApi['obs_valueString'] ?? null,

            'date' =>
            $dataApi['date'],

            'rm' => $srrm,

            'status' =>
            $dataApi['status'],

            'created_at' =>
            date('Y-m-d H:i:s'),

            'obs_system' =>
            $dataApi['obs_system'],

            'category_system' =>
            $dataApi['category_system'],

            'category_code' =>
            $dataApi['category_code'],

            'category_display' =>
            $dataApi['category_display'],

            'codeable_value' =>
            $dataApi['codeable_value'] ?? '',

            'codeable_display' =>
            $dataApi['codeable_display'] ?? '',

            'intr_code' =>
            $dataApi['intr_code'] ?? '',

            'intr_display' =>
            $dataApi['intr_display'] ?? '',

            'performer_idIHS' =>
            $dataApi['performer'] ?? null,
          ])
          ->execute();

        $this->stdout(
          "  [OK] Observation Lab Saved: " .
            "{$dataApi['observation_idIHS']}, " .
            "from serviceRequest: " .
            "{$payloadObs['servicerequest_idIHS']}, " .
            "RM: {$srrm}\n"
        );
      } else {
        $this->stdout(
          "  [Failed] data Hasil Observasi lab " .
            "dengan kode lokal: {$obsLab['TEST_ID']}, " .
            "from serviceRequest: " .
            "{$payloadObs['servicerequest_idIHS']}, " .
            "Rm: {$srrm}, tanggal {$tgl_param}\n\n"
        );

        continue;
      }
    }
  }

  private function getVitalEncounters(
    string $tgl_param,
    string $encounterClass
  ): array {
    $encounters = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_lokalid',
        'practition_idIHS',
        'inprogress_start',
        'class',
      ])
      ->from('ssht_encounter')
      ->where([
        'CAST(inprogress_start AS DATE)' => $tgl_param,
      ])
      ->andWhere([
        'class' => $encounterClass,
      ])
      ->all($this->dbLocal);

    echo "\n...\n";
    echo "ditemukan encounter: " . count($encounters) . " data.\n";

    return $encounters;
  }

  private function sendVitalObservations(
    array $enc,
    array $simrsobs
  ): void {
    $rm = $enc['subject_rm'];

    $mapping = [
      'systolic' => 'tdsys',
      'diastolic' => 'tddias',
      'heart_rate' => 'nadi',
      'body_temp' => 'suhu',
      'respiratory_rate' => 'nafas',
      'height_cm' => 'tb',
      'weight_kg' => 'bb',
    ];

    $obsdata = $simrsobs['observation_data'] ?? [];

    foreach ($mapping as $key => $obsName) {
      if (!isset($obsdata[$key])) {
        continue;
      }

      $payload = [
        'encounterIdIHS' => $enc['idIHS'],
        'obs_name' => $obsName,
        'obs_value' => $obsdata[$key],
        'patient_idIHS' => $enc['subject_idIHS'],
        'patient_nama' => $enc['subject_nama'],
        'practition_idIHS' => $enc['practition_idIHS'],
        'inprogress_start' => $enc['inprogress_start'],
        'rm' => $rm,
        'dok' => $enc['practition_lokalid'],
      ];

      if (!$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::OBSERVATION_CREATE_VITAL
        ),
        payload: $payload,
      )) {
        continue;
      }

      $response = SshtApiBase::request(
        SshtApiUrl::OBSERVATION_CREATE_VITAL,
        [
          'json' => $payload
        ]
      );

      $result = json_decode(
        (string) $response->getBody(),
        true
      );

      $this->stdout("[PROCESS] RM {$rm}\n");
      $this->stdout((string) $response->getBody());

      if (
        isset($result['status']) &&
        (
          $result['status'] == 'true' ||
          $result['status'] === true
        )
      ) {
        $this->saveVitalObservation(
          dataApi: $result['data'] ?? [],
          rm: $rm
        );
      } else {
        $errMsg = $result['error'] ?? 'Unknown Error';

        $this->stdout(
          "[ERR] RM: {$rm} | " .
            Json::encode($errMsg) .
            "\n"
        );
      }
    }
  }

  private function saveVitalObservation(
    array $dataApi,
    string $rm
  ): void {
    $obsIdIhs =
      $dataApi['observation_idIHS'] ?? null;

    if (!$obsIdIhs) {
      return;
    }

    $now = date('Y-m-d H:i:s');

    $this->dbLocal
      ->createCommand()
      ->insert('ssht_observation', [
        'observation_idIHS' => $dataApi['observation_idIHS'],
        'encounter_idIHS' => $dataApi['encounter_idIHS'],
        'subject_idIHS' => $dataApi['subject_idIHS'],
        'obs_system' => $dataApi['obs_system'],
        'obs_code' => $dataApi['obs_code'],
        'obs_display' => $dataApi['obs_display'],
        'obs_value' => $dataApi['obs_value'],
        'date' => $dataApi['date'],
        'rm' => $rm,
        'status' => $dataApi['status'],
        'created_at' => $now,
        'category_system' => $dataApi['category_system'],
        'category_code' => $dataApi['category_code'],
        'category_display' => $dataApi['category_display'],
      ])
      ->execute();

    $this->stdout(
      "[OK] RM: {$rm} | SR_ID: {$obsIdIhs}\n"
    );
  }

  private function stdout(string $message): void
  {
    echo $message;
  }
}
