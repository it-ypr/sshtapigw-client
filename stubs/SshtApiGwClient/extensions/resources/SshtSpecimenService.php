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

class SshtSpecimenService
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

  public function sendRalanSingle(
    string $rm,
    array $enc,
    array $payload,
    array $payloadSpeciment
  ): void {
    if (
      !$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::SPECIMENT_CREATE
        ),
        payload: $payloadSpeciment,
      )
    ) {
      continue;
    }

    $response = SshtApiBase::request(
      SshtApiUrl::SPECIMENT_CREATE,
      [
        'json' => $payloadSpeciment
      ]
    );

    $result = json_decode(
      (string) $response->getBody(),
      true
    );

    if (
      isset($result['status']) &&
      $result['status'] == 'true'
    ) {
      $dataApi = $result['data'] ?? [];

      $speIdIhs = $dataApi['speciment_idIHS'] ?? null;
      $now = date('Y-m-d H:i:s');

      try {
        $this->dbLocal
          ->createCommand()
          ->insert('ssht_speciment', [
            'speciment_idIHS' =>
            $speIdIhs,

            'servicerequest_idIHS' =>
            $dataApi['servicerequest_idIHS'],

            'lokal_sampleID_testID' =>
            $dataApi['lokal_sampleID_testID'],

            'method_code' =>
            $dataApi['method_code'] ?? null,

            'method_display' =>
            $dataApi['method_display'] ?? null,

            'code' =>
            $dataApi['code'] ?? null,

            'display' =>
            $dataApi['display'] ?? null,

            'rm' =>
            $rm,

            'dok' =>
            $dataApi['dok'] ?? null,

            'laborat_ihs' =>
            $payload['petugaslab_idIHS'],

            'laborat_nama' =>
            $payload['petugaslab_nama'],

            'date' =>
            $dataApi['date'],

            'created_at' =>
            $now,

            'updated_at' =>
            $now,
          ])
          ->execute();

        $this->stdout(
          "[+] Sukses Speciment sampleID " .
            "{$payloadSpeciment['sampleID']}, " .
            "Rm: {$rm}, " .
            "Speciment_idIHS: {$speIdIhs}\n"
        );
      } catch (\Throwable $e) {
        $this->stdout(
          "[+] Gagal Save Duplicate Speciment, " .
            "Rm: {$rm}, " .
            "lokal_sampleID_testID: " .
            "{$payloadSpeciment['sampleID']}\n"
        );
      }

      return;
    }

    $this->stdout(
      "[-] Failed Send Speciment sampleID " .
        "{$payloadSpeciment['sampleID']}, " .
        "Rm: {$enc['rm']}\n"
    );
  }
}
