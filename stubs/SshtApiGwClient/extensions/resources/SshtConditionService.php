<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use Yii;
use yii\db\Query;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\util\SshtApiUtil;

class SshtConditionService
{
  private $dbLocal;
  private $dbSimrs;
  private SshtApiDebugger $debugger;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;
    $this->dbSimrs = Yii::$app->db;

    $config = SshtApiBase::getConfig();

    $this->debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );
  }

  /**
   * Create Condition untuk sebuah Encounter.
   *
   * @param string $encounterId
   * @param string $patientId
   * @param string $patientName
   * @param string|null $icdCodes
   * @param string $inprogressStart
   * @param string $inprogressEnd
   * @param string $rm
   * @param string $dokter
   *
   * @return void
   */
  public function sendForEncounter(
    string $encounterId,
    string $patientId,
    string $patientName,
    ?string $icdCodes,
    string $inprogressStart,
    string $inprogressEnd,
    string $rm,
    string $dokter
  ): void {
    $icdList = $this->parseIcdCodes($icdCodes);

    foreach ($icdList as $key => $icd) {
      try {
        $payloadCondition = [
          'encounter_idIHS' => $encounterId,
          'patient_idIHS' => $patientId,
          'patient_nama' => $patientName,
          'conditionCode' => $icd['code'],
          'conditionName' => $icd['display'],
          'inprogress_start' => $inprogressStart,
          'inprogress_end' => $inprogressEnd,
        ];

        if (!$this->debugger->allow(
          SshtApiUtil::genDebugContext(
            SshtApiUrl::CONDITION_CREATE
          ),
          $payloadCondition
        )) {
          continue;
        }

        $resConditionReq = SshtApiBase::request(
          SshtApiUrl::CONDITION_CREATE,
          [
            'json' => $payloadCondition
          ]
        );

        $resCondition = json_decode(
          (string) $resConditionReq->getBody(),
          true
        );

        $conditionIhsId = $resCondition['data']['idIHS'] ?? null;

        if (!$conditionIhsId) {
          throw new Exception(
            "Condition IHS ID tidak ditemukan untuk ICD {$icd['code']}"
          );
        }

        $now = date('Y-m-d H:i:s');

        $this->dbLocal->createCommand()->insert(
          'ssht_condition',
          [
            'condition_idIHS' => $conditionIhsId,
            'encounter_idIHS' => $encounterId,
            'conditionRank' => $key + 1,
            'code' => $icd['code'],
            'display' => $icd['display'],
            'rm' => $rm,
            'dok' => $dokter,
            'created_at' => $now,
            'updated_at' => $now,
          ]
        )->execute();

        echo "Condition OK: {$conditionIhsId} ({$icd['code']})\n";

        sleep(1);
      } catch (Exception $e) {
        echo "Condition Error: {$icd['code']} gagal: {$e->getMessage()}\n";

        sleep(5);
      }
    }
  }

  /**
   * Parse ICD codes dari format SIMRS.
   *
   * @param string|null $icdCodes
   * @return array<int, array{code:string, display:string}>
   */
  private function parseIcdCodes(?string $rawCodes): array
  {
    if (empty($rawCodes))
      return [];

    $mapping = [
      "R50.0" => "R50",
      // "A01.0" => "A01",
      // "Z00.0" => "Z00",
    ];

    $codes = explode(';', $rawCodes);
    $normalizedCodes = [];

    foreach ($codes as $c) {
      $clean = trim(preg_replace('/[^a-zA-Z0-9.]/', '', $c));
      if (!$clean)
        continue;

      if (isset($mapping[$clean])) {
        $clean = $mapping[$clean];
      }
      $normalizedCodes[] = $clean;
    }

    $uniqueCodes = array_unique($normalizedCodes);

    // Query menggunakan database terminologi
    return (new Query())
      ->select(['icd10_code as code', 'icd10_en as display'])
      ->from('icd10')
      ->where(['icd10_code' => $uniqueCodes])
      ->all(Yii::$app->dbsshtterminologi);
  }
}
