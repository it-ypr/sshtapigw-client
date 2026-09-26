<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Yii;
use yii\db\Query;
use Exception;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;

class SshtDiagnosticReportService
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

  public function createRadio(
    string $servicerequestIdIhs,
    string $value,
    string $noradio,
    string $rm
  ): ?string {
    $payload = [
      'servicerequest_idIHS' => $servicerequestIdIhs,
      'value' => $value,
      'noradio' => $noradio,
    ];

    if (!$this->debugger->allow(
      context: SshtApiUtil::genDebugContext(
        SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_RAD
      ),
      payload: $payload,
    )) {
      return null;
    }

    $response = SshtApiBase::request(
      SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_RAD,
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

    $diagnosticReportIdIhs =
      $resData['diagnosticreport_idIHS'] ?? null;

    if (!$diagnosticReportIdIhs) {
      return null;
    }

    $now = date('Y-m-d H:i:s');

    $this->dbLocal->createCommand()
      ->insert('ssht_diagnosticreport', [
        'diagnosticreport_idIHS' => $diagnosticReportIdIhs,
        'encounter_idIHS' => $resData['encounter_idIHS'] ?? null,
        'servicerequest_idIHS' => $servicerequestIdIhs,
        'subject_idIHS' => $resData['subject_idIHS'] ?? null,
        // 'rm' => $resData['rm'] ?? null,
        'rm' => $rm ?? null,
        'date' => $resData['date'] ?? null,
        'status' => $resData['status'] ?? null,
        'created_at' => $now,
        'category_system' => $resData['category_system'] ?? null,
        'category_display' => $resData['category_display'] ?? null,
        'category_code' => $resData['category_code'] ?? null,
      ])
      ->execute();

    return $diagnosticReportIdIhs;
  }

  public function existsRadio(
    string $rm,
    string $tgl_param,
    string $servicerequestIdIhs
  ): bool {
    return (new Query())
      ->from('ssht_diagnosticreport')
      ->where([
        'rm' => $rm,
        'category_code' => 'RAD',
        'servicerequest_idIHS' => $servicerequestIdIhs,
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
    echo "--- TASK SSHT DiagnosticReport Lab (Ralan): [{$tgl_param}] ---\n";

    try {
      $serviceRequestLab = $this->getLabServiceRequests(
        tgl_param: $tgl_param,
        encounterClass: 'AMB'
      );

      if (empty($serviceRequestLab)) {
        $this->stdout(
          "[!] Tydac ada data order serviceRequestLab tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($serviceRequestLab as $srlab) {
        $this->sendLabDiagnosticReport(
          tgl_param: $tgl_param,
          srlab: $srlab
        );
      }
    } catch (Exception $e) {
      $this->stdout("[CRITICAL] " . $e->getMessage() . "\n");
    }
  }

  public function sendLabUgd(string $tgl_param): void
  {
    echo "--- TASK SSHT DiagnosticReport Lab (UGD): [{$tgl_param}] ---\n";

    try {
      $serviceRequestLab = $this->getLabServiceRequests(
        tgl_param: $tgl_param,
        encounterClass: 'EMER'
      );

      if (empty($serviceRequestLab)) {
        $this->stdout(
          "[!] Tydac ada data order serviceRequestLab tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($serviceRequestLab as $srlab) {
        $this->sendLabDiagnosticReport(
          tgl_param: $tgl_param,
          srlab: $srlab
        );
      }
    } catch (Exception $e) {
      $this->stdout("[CRITICAL] " . $e->getMessage() . "\n");
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
          "tanggal {$tgl_param}, RM {$rm}\n"
      );
      return;
    }

    foreach ($serviceRequestLab as $srlab) {
      $servicerequestId = $srlab['servicerequest_idIHS'];

      $checkDr = (new Query())
        ->from('ssht_diagnosticreport')
        ->where([
          'rm' => $srlab['rm'],
          'servicerequest_idIHS' => $servicerequestId,
          'category_code' => 'LAB',
        ])
        ->andWhere(['like', 'date', $tgl_param])
        ->exists($this->dbLocal);

      if ($checkDr) {
        $this->stdout(
          "[!] Skip: DiagnosticReport sudah ada untuk " .
            "serviceRequestLab: {$servicerequestId}, " .
            "RM: {$srlab['rm']}, tanggal {$tgl_param}\n"
        );
        continue;
      }

      $payload = [
        'servicerequest_idIHS' => $servicerequestId,
        'value' => '-',
        'speciment_idIHS' => $srlab['speciment_idIHS'],
        'srid' => $srlab['srid'],
      ];

      if (
        !$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB
          ),
          payload: $payload,
        )
      ) {
        continue;
      }

      $resR = SshtApiBase::request(
        SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB,
        [
          'json' => $payload
        ]
      );

      if (
        $resR->getStatusCode() == 200 ||
        $resR->getStatusCode() == 201
      ) {
        $resRReq = json_decode(
          (string) $resR->getBody(),
          true
        );

        print_r($resRReq);
        print_r(json_encode($resRReq));

        $this->stdout(
          "  [OK] Report Saved: {$srlab['rm']}\n"
        );

        continue;
      }
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

  private function sendLabDiagnosticReport(
    string $tgl_param,
    array $srlab
  ): void {
    $servicerequestId = $srlab['servicerequest_idIHS'];
    $specimentId = $srlab['speciment_idIHS'];
    $rm = $srlab['rm'];

    $checkDr = (new Query())
      ->from('ssht_diagnosticreport')
      ->where([
        'rm' => $rm,
        'servicerequest_idIHS' => $servicerequestId,
        'category_code' => 'LAB',
      ])
      ->andWhere(['like', 'date', $tgl_param])
      ->exists($this->dbLocal);

    if ($checkDr) {
      $this->stdout(
        "[!] Skip: DiagnosticReport sudah ada untuk " .
          "serviceRequestLab: {$servicerequestId}, " .
          "RM: {$rm}, tanggal {$tgl_param}\n"
      );
      return;
    }

    if (empty($servicerequestId) || empty($specimentId)) {
      $this->stdout(
        "[!] Skip: id_ihs service request atau speciment tidak ditemukan\n"
      );
      return;
    }

    $payload = [
      'servicerequest_idIHS' => $servicerequestId,
      'value' => '-',
      'speciment_idIHS' => $specimentId,
      'srid' => $srlab['srid'],
    ];

    if (
      !$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB
        ),
        payload: $payload,
      )
    ) {
      return;
    }

    $resR = SshtApiBase::request(
      SshtApiUrl::DIAGNOSTIC_REPORT_CREATE_LAB,
      [
        'json' => $payload
      ]
    );

    if (
      $resR->getStatusCode() == 200 ||
      $resR->getStatusCode() == 201
    ) {
      $resRReq = json_decode(
        (string) $resR->getBody(),
        true
      );

      print_r($resRReq);
      print_r(json_encode($resRReq));

      $resDataR = $resRReq['data'] ?? [];

      $this->dbLocal
        ->createCommand()
        ->insert('ssht_diagnosticreport', [
          'diagnosticreport_idIHS' =>
          $resDataR['diagnosticreport_idIHS'],

          'encounter_idIHS' =>
          $resDataR['encounter_idIHS'],

          'servicerequest_idIHS' =>
          $servicerequestId,

          'subject_idIHS' =>
          $resDataR['subject_idIHS'],

          'rm' => $rm,

          'date' =>
          $resDataR['date'],

          'status' =>
          $resDataR['status'],

          'created_at' =>
          date('Y-m-d H:i:s'),

          'category_system' =>
          $resDataR['category_system'],

          'category_display' =>
          $resDataR['category_display'],

          'category_code' =>
          $resDataR['category_code'],
        ])
        ->execute();

      $this->stdout(
        "  [OK] Report Saved: {$rm}\n"
      );

      return;
    }

    $this->stdout(
      "  [Gagal] Gagal save di db, " .
        "diagnosticreport_idIHS: " .
        ($resDataR['diagnosticreport_idIHS'] ?? '-') .
        ", RM {$rm}.\n"
    );
  }

  private function stdout(string $message): void
  {
    echo $message;
  }
}
