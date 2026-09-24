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

class SshtMedicationDispenseService
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

  public function generateRalanSingle(
    string $tgl_param,
    string $rm
  ): void {
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
        ->from('ssht_medication_request smr')
        ->where(['smr.send_status' => 'S'])
        ->andWhere(['smr.rm' => $rm])
        ->limit($this->config['medication_cron_limit'])
        ->all($this->dbLocal);

      if (empty($taskMedicationDispense)) {
        echo "[!] Tydac ada data task medicationRequest\n";
        return;
      }

      foreach ($taskMedicationDispense as $key => $record) {

        $rm = $record['rm'];
        $encounterIdIHS = $record['encounter_idIHS'];

        $payload = json_decode(
          $record['payload'],
          true
        );

        $payloadMedication = array_merge($payload, [
          'medicationRequest_idIHS' =>
          $record['medicationrequest_idIHS'],

          'performer_idIHS' =>
          $record['petugas_idIHS'],

          'performer_nama' =>
          $record['petugas_nama'],

          'location_idIHS' =>
          $this->config['location_medication_ralan_ihs'],

          'location_nama' =>
          $this->config['location_medication_ralan_display'],
        ]);

        // Check apakah MedicationDispense sudah pernah dibuat
        $checkMedicationDispense = (new Query())
          ->select([
            'id',
            'medicationdispense_idIHS',
            'identifier_noresep',
            'identifier_noresep_index',
          ])
          ->from('ssht_medication_dispense')
          ->where([
            'identifier_noresep_index' =>
            $payloadMedication['identifier_noresep_index']
          ])
          ->one($this->dbLocal);

        if (!empty($checkMedicationDispense)) {
          continue;
        }

        if (
          !$this->debugger->allow(
            context: [
              "message" =>
              "test generate task medication Dispense: "
                . $payloadMedication['identifier_noresep_index']
            ],
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        // Simpan task record ke DB
        $this->dbLocal->createCommand()
          ->insert('ssht_medication_dispense', [

            'medicationdispense_idIHS' => '',

            'medication_idIHS' =>
            $payloadMedication['medication_idIHS'] ?? '',

            'medicationrequest_idIHS' =>
            $payloadMedication['medicationRequest_idIHS'],

            'encounter_idIHS' =>
            $encounterIdIHS,

            'identifier_noresep' =>
            $payloadMedication['identifier_noresep'],

            'identifier_noresep_index' =>
            $payloadMedication['identifier_noresep_index'],

            'rm' =>
            $payloadMedication['rm'],

            'subject_idIHS' =>
            $payloadMedication['patient_idIHS'],

            'dok' =>
            $payloadMedication['dok'],

            'requester_idIHS' =>
            $payloadMedication['practition_idIHS'],

            'local_id' =>
            $payloadMedication['local_id'] ?? '',

            // Trace petugas
            'petugas_idIHS' =>
            $record['petugas_idIHS'],

            'petugas_nama' =>
            $record['petugas_nama'],

            'petugas_ambil_idIHS' =>
            $record['petugas_ambil_idIHS'],

            'petugas_ambil_nama' =>
            $record['petugas_ambil_nama'],

            'created_at' =>
            date('Y-m-d H:i:s'),

            'updated_at' =>
            date('Y-m-d H:i:s'),

            'send_status' =>
            'P',

            'payload' =>
            json_encode($payloadMedication),

          ])
          ->execute();

        echo "   > MedicationRequest identifier generated: "
          . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED')
          . "\n";

        print_r(json_encode($payloadMedication));
      }
    } catch (Exception $e) {

      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";

      sleep(1);
    }
    // end function generateRalanSingle()
  }

  public function generateTask(): void
  {
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
        "smr.petugas_idIHS",
        "smr.petugas_nama",
        "smr.petugas_ambil_idIHS",
        "smr.petugas_ambil_nama",
      ])
      ->from('ssht_medication_request smr')
      ->leftJoin(
        'ssht_medication_dispense smd',
        'smd.identifier_noresep_index = smr.identifier_noresep_index'
      )
      ->where(['smr.send_status' => 'S'])
      ->andWhere(['smd.id' => null])
      ->orderBy([
        'smr.id' => SORT_ASC
      ])
      ->limit($this->config['medication_cron_limit'])
      ->all($this->dbLocal);

    foreach ($taskMedicationDispense as $key => $record) {

      $encounterIdIHS = $record['encounter_idIHS'];

      $payload = json_decode(
        $record['payload'],
        true
      );

      $payloadMedication = array_merge($payload, [
        'medicationRequest_idIHS' =>
        $record['medicationrequest_idIHS'],

        'performer_idIHS' =>
        $record['petugas_idIHS'],

        'performer_nama' =>
        $record['petugas_nama'],

        'location_idIHS' =>
        $this->config['location_medication_ralan_ihs'],

        'location_nama' =>
        $this->config['location_medication_ralan_display'],
      ]);

      if (
        !$this->debugger->allow(
          context: [
            "message" =>
            "test generate task medication Dispense: "
              . $payloadMedication['identifier_noresep_index']
          ],
          payload: $payloadMedication,
        )
      ) {
        continue;
      }

      try {

        $this->dbLocal->createCommand()
          ->insert('ssht_medication_dispense', [

            'medicationdispense_idIHS' => '',

            'medication_idIHS' =>
            $payloadMedication['medication_idIHS'] ?? '',

            'medicationrequest_idIHS' =>
            $payloadMedication['medicationRequest_idIHS'],

            'encounter_idIHS' =>
            $encounterIdIHS,

            'identifier_noresep' =>
            $payloadMedication['identifier_noresep'],

            'identifier_noresep_index' =>
            $payloadMedication['identifier_noresep_index'],

            'rm' =>
            $payloadMedication['rm'],

            'subject_idIHS' =>
            $payloadMedication['patient_idIHS'],

            'dok' =>
            $payloadMedication['dok'],

            'requester_idIHS' =>
            $payloadMedication['practition_idIHS'],

            'local_id' =>
            $payloadMedication['local_id'] ?? '',

            'petugas_idIHS' =>
            $record['petugas_idIHS'],

            'petugas_nama' =>
            $record['petugas_nama'],

            'petugas_ambil_idIHS' =>
            $record['petugas_ambil_idIHS'],

            'petugas_ambil_nama' =>
            $record['petugas_ambil_nama'],

            'created_at' =>
            date('Y-m-d H:i:s'),

            'updated_at' =>
            date('Y-m-d H:i:s'),

            'send_status' =>
            'P',

            'payload' =>
            json_encode($payloadMedication),

          ])
          ->execute();

        echo "   > MedicationRequest identifier generated: "
          . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED')
          . "\n";

        print_r(json_encode($payloadMedication));
      } catch (Yii\db\IntegrityException $e) {
        continue;
      }
    }
  }

  public function sendTaskRalan(): void
  {
    // generate task terlebih dahulu
    $this->generateTask();

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
      ->orderBy([
        'smd.id' => SORT_ASC
      ])
      ->limit($this->config['medication_cron_limit'])
      ->all($this->dbLocal);

    if (empty($taskMedicationDispense)) {
      echo "[!] Tydac ada data task medicationDispense\n";
      return;
    }

    foreach ($taskMedicationDispense as $key => $record) {

      $payload = json_decode(
        $record['payload'],
        true
      );

      // CHECKING SUDAH TER SYNC MEDICATION belum..
      $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId(
        $payload['local_id']
      );

      if (!$getLocalObt) {

        echo "[-] tydac ada local_id tsb..\n";

        $this->dbLocal->createCommand()
          ->update(
            'ssht_medication_dispense',
            [
              'send_status' => 'M',
              'send_error_code' => '',
              'send_error_message' =>
              'Obat: ' . $payload['local_id']
                . ' ,dengan identifier_noresep_index: '
                . $payload['identifier_noresep_index']
                . ' ,Perlu mapping Obat (KFA & sync Medication) '
                . 'untuk kirim MedicationDispense..',
              'payload' => json_encode($payload),
              'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )
          ->execute();

        echo "[-] {$payload['identifier_noresep_index']}"
          . " dengan Obat: {$payload['local_id']}"
          . " Perlu dimaping KFA dan Sync Medication..\n";

        continue;
      }

      if (
        !$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::MEDICATION_DISPENSE_CREATE
          ),
          payload: $payload,
        )
      ) {
        continue;
      }

      $response = SshtApiBase::request(
        SshtApiUrl::MEDICATION_DISPENSE_CREATE,
        [
          'json' => $payload
        ]
      );

      $resMedReq = json_decode(
        (string) $response->getBody(),
        true
      );

      echo "[+] body-response:\n";
      print_r(json_encode($resMedReq));

      if (
        $response->getStatusCode() == 400 ||
        $response->getStatusCode() == 422 ||
        $response->getStatusCode() == 500 ||
        isset($resMedReq['errors'])
      ) {

        $this->dbLocal->createCommand()
          ->update(
            'ssht_medication_dispense',
            [
              'send_status' => 'F',
              'send_error_code' =>
              $response->getStatusCode(),
              'send_error_message' =>
              json_encode($resMedReq['errors'])
                ?? $response->getMessage(),
              'payload' =>
              json_encode($payload),
              'updated_at' =>
              date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )
          ->execute();

        continue;
      }

      $medicationDispense_idIHS =
        $resMedReq['data']['medicationDispense_idIHS']
        ?? null;

      sleep(1);

      if ($medicationDispense_idIHS) {

        $MedReqData = $resMedReq['data'];

        $this->dbLocal->createCommand()
          ->update(
            'ssht_medication_dispense',
            [
              'medicationdispense_idIHS' =>
              $medicationDispense_idIHS,

              'medicationrequest_idIHS' =>
              $MedReqData['medicationRequest_idIHS'],

              'encounter_idIHS' =>
              $MedReqData['encounter_idIHS'],

              'contained' =>
              json_encode($MedReqData['contained']),

              'category_code' =>
              $MedReqData['category_code'],

              'category_display' =>
              $MedReqData['category_display'],

              'category_system' =>
              $MedReqData['category_system'],

              'performer_idIHS' =>
              $MedReqData['performer_idIHS'],

              'quantity_system' =>
              $MedReqData['quantity_system'],

              'quantity_code' =>
              $MedReqData['quantity_code'],

              'quantity_unit' =>
              $MedReqData['quantity_unit'],

              'quantity_value' =>
              $MedReqData['quantity_value'],

              'when_prepared' =>
              $MedReqData['when_prepared'],

              'when_handed_over' =>
              $MedReqData['when_handed_over'],

              'dosage_instruction' =>
              json_encode($MedReqData['dosage_instruction']),

              'status' =>
              $MedReqData['status'],

              'local_id' =>
              $MedReqData['local_id'],

              'updated_at' =>
              date('Y-m-d H:i:s'),

              'send_at' =>
              date('Y-m-d H:i:s'),

              'send_status' =>
              'S',

              'payload' =>
              json_encode($payload),

              'send_error_message' =>
              '',

              'send_error_code' =>
              '',
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )
          ->execute();

        echo "\nMedicationDispense: "
          . ($medicationDispense_idIHS ?? 'FAILED')
          . "\n";
      }
    }
  }
}
