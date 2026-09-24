<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use GuzzleHttp\Client;
use Yii;
use yii\db\Query;

use common\services\SshtApiGwClient\SshtApiDebugger;

class SshtImagingStudyService
{
  private $dbLocal;
  private SshtApiDebugger $debugger;
  private array $config;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;

    $this->config = Yii::$app->params['SSHTApiConfig'] ?? [];

    $this->debugger = new SshtApiDebugger(
      enabled: $this->config['debug'] ?? false
    );
  }

  /**
   * php yii ssht-api-client/send-imaging-study-ralan 2026-05-01
   */
  public function sendRalan(string $tgl_param): void
  {
    $this->send(
      tgl_param: $tgl_param,
      encounterClass: 'AMB',
      label: 'Ralan'
    );
  }

  /**
   * php yii ssht-api-client/send-imaging-study-ranap 2026-05-01
   */
  public function sendRanap(string $tgl_param): void
  {
    // Belom...
  }

  /**
   * php yii ssht-api-client/send-imaging-study-ugd 2026-05-01
   */
  public function sendUgd(string $tgl_param): void
  {
    $this->send(
      tgl_param: $tgl_param,
      encounterClass: 'EMER',
      label: 'UGD'
    );
  }

  private function send(
    string $tgl_param,
    string $encounterClass,
    string $label
  ): void {
    echo "--- TASK SSHT ImagingStudy Radio ({$label}): [{$tgl_param}] ---\n";

    $dicomRouterName = $this->config['dicom_router_name'];

    try {
      // 1. Ambil ServiceRequest dari DB lokal
      $records = (new Query())
        ->select([
          'ssht_servicerequest.servicerequest_idIHS as servicerequest_idIHS',
          'ssht_servicerequest.encounter_idIHS as encounter_idIHS',
          'ssht_servicerequest.acsn as acsn',
          'ssht_servicerequest.display as display',
          'ssht_servicerequest.rm as rm',
          'ssht_servicerequest.patient_idIHS as patient_idIHS',
          'se.class'
        ])
        ->from('ssht_servicerequest')
        ->leftJoin(
          'ssht_encounter se',
          'ssht_servicerequest.encounter_idIHS = se.idIHS'
        )
        ->where([
          'CAST(ssht_servicerequest.date AS DATE)' => $tgl_param,
          'ssht_servicerequest.category_display' => 'Imaging',
          'ssht_servicerequest.category_code' => '363679005',
          'se.class' => $encounterClass,
        ])
        ->all($this->dbLocal);

      if (empty($records)) {
        $this->stdout(
          "[!] Gak ada data ServiceRequest untuk tanggal {$tgl_param}\n"
        );

        return;
      }

      $client = new Client([
        'base_uri' => $this->config['orthanc_url'],
        'auth' => [
          $this->config['orthanc_auth_user'],
          $this->config['orthanc_auth_password']
        ],
        'timeout' => 30.0,
      ]);

      foreach ($records as $row) {
        $this->sendStudy(
          client: $client,
          row: $row,
          dicomRouterName: $dicomRouterName
        );
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] Error: {$e->getMessage()}\n"
      );
    }
  }

  private function sendStudy(
    Client $client,
    array $row,
    string $dicomRouterName
  ): void {
    $acsn = $row['acsn'];
    $patientIdIhs = $row['patient_idIHS'];
    $rm = $row['rm'];

    $this->stdout(
      "\n[*] Processing RM: {$rm} | PatientID IHS: {$patientIdIhs}\n"
    );

    $this->stdout("[*] ACSN: {$acsn}\n");

    // 2. Cari Study di Orthanc berdasarkan AccessionNumber
    $findRes = $client->post('/tools/find', [
      'json' => [
        'Level' => 'Study',
        'Query' => [
          'AccessionNumber' => (string) $acsn
        ]
      ]
    ]);

    $studies = json_decode(
      $findRes->getBody()->getContents(),
      true
    );

    if (empty($studies)) {
      $this->stdout(
        "  [-] Skip: Study tidak ditemukan di Orthanc " .
          "untuk ACSN {$acsn}\n"
      );

      return;
    }

    print_r($studies);

    foreach ($studies as $studyId) {
      $this->modifyAndSendStudy(
        client: $client,
        studyId: $studyId,
        patientIdIhs: $patientIdIhs,
        acsn: $acsn,
        dicomRouterName: $dicomRouterName
      );
    }
  }

  private function modifyAndSendStudy(
    Client $client,
    string $studyId,
    string $patientIdIhs,
    string $acsn,
    string $dicomRouterName
  ): void {
    // 3. Modify metadata
    $modifyRes = $client->post(
      "/studies/{$studyId}/modify",
      [
        'json' => [
          'Replace' => [
            'PatientID' => (string) $patientIdIhs,
          ],
          'Force' => true
        ]
      ]
    );

    $dataModify = json_decode(
      $modifyRes->getBody()->getContents(),
      true
    );

    if (empty($dataModify)) {
      $this->stdout(
        "  [!] Gagal modifikasi metadata Study {$studyId}\n"
      );

      return;
    }

    $newStudyId = $dataModify['ID'];

    $this->stdout(
      "  [OK] Metadata modified. " .
        "New Orthanc ID: {$newStudyId}\n"
    );

    // 4. Hapus study lama
    $client->delete("/studies/{$studyId}");

    $this->stdout(
      "  [OK] Study lama ({$studyId}) telah dihapus dari Orthanc.\n"
    );

    // 5. Kirim study baru ke DICOM Router
    $storeRes = $client->post(
      "/modalities/{$dicomRouterName}/store",
      [
        'body' => $newStudyId
      ]
    );

    if ($storeRes->getStatusCode() === 200) {
      $this->stdout(
        "  [OK] Berhasil kirim ke {$dicomRouterName} " .
          "dengan ACSN {$acsn}\n"
      );
    } else {
      $this->stdout(
        "  [!] Gagal kirim ke Router: " .
          $storeRes->getBody()->getContents() . "\n"
      );
    }
  }

  private function stdout(string $message): void
  {
    echo $message;
  }
}
