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

class SshtMedicationRequestService
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

  /*
   * generateRalanSingle($tgl_param, $rm)
   *
   */
  public function generateRalanSingle(
    string $tgl_param,
    string $rm
  ): void {
    try {

      $encounter = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'subject_idIHS',
          'subject_nama',
          'practition_idIHS',
          'practition_nama',
          'practition_lokalid',
          'inprogress_start',
          'inprogress_end',
          'class'
        ])
        ->from('ssht_encounter')
        ->where([
          'CAST(inprogress_start AS DATE)' => $tgl_param
        ])
        ->andWhere([
          'subject_rm' => $rm
        ])
        ->andWhere([
          'class' => 'AMB'
        ])
        ->all($this->dbLocal);

      if (empty($encounter)) {
        echo "[!] Tydac ada data encounter tanggal {$tgl_param}\n";
        return;
      }

      print_r("Data array encounter:\n");
      print_r($encounter[0]);

      foreach ($encounter as $key => $record) {

        $rm = $record['subject_rm'];
        $encounterIdIHS = $record['idIHS'];

        $simrs = SshtApiQueryMapping::queryMedicationRalan(
          tgl_param: $tgl_param,
          rm_param: $rm
        );

        if (!$simrs) {
          echo "[-] SKIP: kunjungan encounter RM: {$rm} "
            . "tidak ditemukan data resep obat..\n";

          continue;
        }

        print_r($simrs);

        foreach ($simrs as $key => $obt) {

          $identifier_resep =
            SshtApiUtil::genIdentifierResepMedication(
              $tgl_param,
              $obt['resep'],
              $key
            );

          print_r($identifier_resep);

          $aturanpakai =
            SshtApiUtil::genAturanPakaiObat(
              kali: $obt['kali'],
              hari: $obt['hari'],
              sediaan: $obt['sediaan'] ?? "",
              waktu: $obt['waktu'] ?? ""
            );

          $payloadMedication = [
            'encounter_idIHS' =>
            $encounterIdIHS,

            'medication_idIHS' =>
            $obt['medication_idIHS'],

            'patient_idIHS' =>
            $record['subject_idIHS'],

            'patient_nama' =>
            $record['subject_nama'],

            'rm' =>
            $record['subject_rm'],

            'dok' =>
            $record['practition_lokalid'],

            'practition_idIHS' =>
            $record['practition_idIHS'],

            'practition_nama' =>
            $record['practition_nama'],

            'inprogress_end' =>
            $record['inprogress_end'],

            'identifier_noresep' =>
            $identifier_resep->identifier_noresep,

            'identifier_noresep_index' =>
            $identifier_resep->identifier_noresep_index,

            'local_id' =>
            (string) $obt['id_local'] ?? "",

            'kfa_code' =>
            $obt['kfa_code'] ?? "",

            'kfa_display' =>
            $obt['kfa_display'] ?? "",

            'kfa_bza' =>
            isset($obt['kfa_bza'])
              ? json_decode($obt['kfa_bza'], true)
              : "",

            'kfa_form' =>
            isset($obt['kfa_form'])
              ? json_decode($obt['kfa_form'], true)
              : "",

            'kfa_route' =>
            isset($obt['kfa_route'])
              ? json_decode($obt['kfa_route'], true)
              : "",

            'jumlah' =>
            (string) trim($obt['jumlah']),

            'aturanpakai' =>
            $aturanpakai,
          ];

          /*
           * Cek apakah identifier_noresep_index
           * sudah pernah dibuat.
           */
          $checkMedicationRequest = (new Query())
            ->select([
              'id',
              'medicationrequest_idIHS',
              'identifier_noresep',
              'identifier_noresep_index',
            ])
            ->from('ssht_medication_request')
            ->where([
              'identifier_noresep_index' =>
              $payloadMedication['identifier_noresep_index']
            ])
            ->one($this->dbLocal);

          if (!empty($checkMedicationRequest)) {
            continue;
          }

          if (!$this->debugger->allow(
            context: [
              'message' =>
              'test generate task medication Request: '
                . $payloadMedication['identifier_noresep_index']
            ],
            payload: $payloadMedication,
          )) {
            continue;
          }

          /*
           * Simpan task record ke DB.
           */
          $this->dbLocal
            ->createCommand()
            ->insert('ssht_medication_request', [
              'medicationrequest_idIHS' => '',
              'medication_idIHS' =>
              $obt['medication_idIHS'] ?? '',

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
              $payloadMedication['local_id'] ?? "",

              'petugas_idIHS' =>
              $obt['ihs_petugas'],

              'petugas_nama' =>
              $obt['petugas_nama'],

              'petugas_ambil_idIHS' =>
              $obt['ihs_petugas_ambil'],

              'petugas_ambil_nama' =>
              $obt['petugas_ambil_nama'],

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
            . (
              $payloadMedication['identifier_noresep_index']
              ?? 'FAILED'
            )
            . "\n";

          print_r(json_encode($payloadMedication));
        }
      }
    } catch (Exception $e) {

      echo " ERROR: " . $e->getMessage() . "\n";
      echo $e;

      sleep(1);
    }
    // end function generateRalanSingle()
  }

  public function generateRalan(string $tgl_param): void
  {
    echo "--- TASK SSHT Generate MedicationRequest (Ralan): [{$tgl_param}] ---\n";

    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_idIHS',
        'practition_nama',
        'practition_lokalid',
        'inprogress_start',
        'inprogress_end',
        'class'
      ])
      ->from('ssht_encounter')
      ->where([
        'CAST(inprogress_start AS DATE)' => $tgl_param
      ])
      ->andWhere([
        'class' => 'AMB'
      ])
      ->all($this->dbLocal);

    if (empty($encounter)) {
      echo "[!] Tydac ada data encounter tanggal {$tgl_param}\n";
      return;
    }

    foreach ($encounter as $record) {

      $rm = $record['subject_rm'];
      $encounterIdIHS = $record['idIHS'];

      $simrs = SshtApiQueryMapping::queryMedicationRalan(
        tgl_param: $tgl_param,
        rm_param: $rm
      );

      if (!$simrs) {
        echo "[-] SKIP: kunjungan encounter RM: {$rm} "
          . "tidak ditemukan data resep obat..\n";

        continue;
      }

      foreach ($simrs as $key => $obt) {

        print_r($obt);

        $identifier_resep =
          SshtApiUtil::genIdentifierResepMedication(
            $tgl_param,
            $obt['resep'],
            $key
          );

        $aturanpakai =
          SshtApiUtil::genAturanPakaiObat(
            kali: $obt['kali'],
            hari: $obt['hari'],
            sediaan: $obt['sediaan'] ?? "",
            waktu: $obt['waktu'] ?? ""
          );

        $payloadMedication = [
          'encounter_idIHS' =>
          $encounterIdIHS,

          'medication_idIHS' =>
          (string) $obt['medication_idIHS'],

          'patient_idIHS' =>
          $record['subject_idIHS'],

          'patient_nama' =>
          $record['subject_nama'],

          'rm' =>
          $record['subject_rm'],

          'dok' =>
          $record['practition_lokalid'],

          'practition_idIHS' =>
          $record['practition_idIHS'],

          'practition_nama' =>
          $record['practition_nama'],

          'inprogress_end' =>
          $record['inprogress_end'],

          'identifier_noresep' =>
          $identifier_resep->identifier_noresep,

          'identifier_noresep_index' =>
          $identifier_resep->identifier_noresep_index,

          'local_id' =>
          (string) $obt['id_local'] ?? "",

          'kfa_code' =>
          $obt['kfa_code'] ?? "",

          'kfa_display' =>
          $obt['kfa_display'] ?? "",

          'kfa_bza' =>
          isset($obt['kfa_bza'])
            ? json_decode($obt['kfa_bza'], true)
            : "",

          'kfa_form' =>
          isset($obt['kfa_form'])
            ? json_decode($obt['kfa_form'], true)
            : "",

          'kfa_route' =>
          isset($obt['kfa_route'])
            ? json_decode($obt['kfa_route'], true)
            : "",

          'jumlah' =>
          (string) trim($obt['jumlah']),

          'kali' =>
          SshtApiUtil::parseDosisFrekuensiLocal(
            (string) trim($obt['kali'])
          ),

          'hari' =>
          (string) trim($obt['hari']),

          'aturanpakai' =>
          $aturanpakai,
        ];

        if (!$this->debugger->allow(
          context: [
            'message' =>
            'test generate task medication Request: '
              . $payloadMedication['identifier_noresep_index']
          ],
          payload: $payloadMedication,
        )) {
          continue;
        }

        try {

          $this->dbLocal
            ->createCommand()
            ->insert('ssht_medication_request', [
              'medicationrequest_idIHS' => '',

              'medication_idIHS' =>
              $payloadMedication['medication_idIHS'] ?? "",

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
              $payloadMedication['local_id'] ?? "",

              'petugas_idIHS' =>
              $obt['ihs_petugas'],

              'petugas_nama' =>
              $obt['petugas_nama'],

              'petugas_ambil_idIHS' =>
              $obt['ihs_petugas_ambil'],

              'petugas_ambil_nama' =>
              $obt['petugas_ambil_nama'],

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
        } catch (Yii\db\IntegrityException $e) {
          continue;
        }

        echo "   > MedicationRequest identifier generated: "
          . (
            $payloadMedication['identifier_noresep_index']
            ?? 'FAILED'
          )
          . "\n";

        print_r(json_encode($payloadMedication));
      }
    }
    // end function generateRalan()
  }

  public function generateUgd(string $tgl_param): void
  {
    print_r("--- TASK SSHT Generate MedicationRequest (UGD): [{$tgl_param}] ---\n");

    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_idIHS',
        'practition_nama',
        'practition_lokalid',
        'inprogress_start',
        'inprogress_end',
        'class'
      ])
      ->from('ssht_encounter')
      ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->andWhere(['class' => 'EMER'])
      ->all($this->dbLocal);

    if (empty($encounter)) {
      echo "[!] Tydac ada data encounter tanggal {$tgl_param}\n";
      return;
    }

    foreach ($encounter as $key => $record) {

      $rm = $record['subject_rm'];
      $encounterIdIHS = $record['idIHS'];

      $simrs = SshtApiQueryMapping::queryMedicationRalan(
        tgl_param: $tgl_param,
        rm_param: $rm
      );

      if (!$simrs) {
        echo "[-] SKIP: kunjungan encounter RM: $rm tidak ditemukan data resep obat..\n";
        continue;
      }

      foreach ($simrs as $key => $obt) {

        print_r($obt);

        $identifier_resep = SshtApiUtil::genIdentifierResepMedication(
          $tgl_param,
          $obt['resep'],
          $key
        );

        $aturanpakai = SshtApiUtil::genAturanPakaiObat(
          kali: $obt['kali'],
          hari: $obt['hari'],
          sediaan: $obt['sediaan'] ?? "",
          waktu: $obt['waktu'] ?? ""
        );

        $payloadMedication = [
          "encounter_idIHS" => $encounterIdIHS,
          "medication_idIHS" => (string) $obt['medication_idIHS'],
          "patient_idIHS" => $record['subject_idIHS'],
          "patient_nama" => $record['subject_nama'],
          "rm" => $record['subject_rm'],
          "dok" => $record['practition_lokalid'],
          "practition_idIHS" => $record['practition_idIHS'],
          "practition_nama" => $record['practition_nama'],
          "inprogress_end" => $record['inprogress_end'],
          "identifier_noresep" => $identifier_resep->identifier_noresep,
          "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
          "local_id" => (string) $obt['id_local'] ?? "",
          "kfa_code" => $obt['kfa_code'] ?? "",
          "kfa_display" => $obt['kfa_display'] ?? "",
          "kfa_bza" => isset($obt['kfa_bza'])
            ? json_decode($obt['kfa_bza'], true)
            : "",
          "kfa_form" => isset($obt['kfa_form'])
            ? json_decode($obt['kfa_form'], true)
            : "",
          "kfa_route" => isset($obt['kfa_route'])
            ? json_decode($obt['kfa_route'], true)
            : "",
          "jumlah" => (string) trim($obt['jumlah']),
          "kali" => SshtApiUtil::parseDosisFrekuensiLocal(
            (string) trim($obt['kali'])
          ),
          "hari" => (string) trim($obt['hari']),
          "aturanpakai" => $aturanpakai,
        ];

        if (
          !$this->debugger->allow(
            context: [
              "message" =>
              "test generate task medication Request: "
                . $payloadMedication['identifier_noresep_index']
            ],
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        try {
          $this->dbLocal->createCommand()
            ->insert('ssht_medication_request', [
              'medicationrequest_idIHS' => '',
              'medication_idIHS' => $payloadMedication["medication_idIHS"] ?? "",
              'encounter_idIHS' => $encounterIdIHS,

              'identifier_noresep' =>
              $payloadMedication["identifier_noresep"],
              'identifier_noresep_index' =>
              $payloadMedication['identifier_noresep_index'],

              'rm' => $payloadMedication['rm'],
              'subject_idIHS' => $payloadMedication['patient_idIHS'],
              'dok' => $payloadMedication['dok'],
              'requester_idIHS' =>
              $payloadMedication['practition_idIHS'],

              'local_id' => $payloadMedication['local_id'] ?? "",

              'petugas_idIHS' => $obt['ihs_petugas'],
              'petugas_nama' => $obt['petugas_nama'],
              'petugas_ambil_idIHS' => $obt['ihs_petugas_ambil'],
              'petugas_ambil_nama' => $obt['petugas_ambil_nama'],

              'created_at' => date('Y-m-d H:i:s'),
              'updated_at' => date('Y-m-d H:i:s'),

              'send_status' => 'P',
              'payload' => json_encode($payloadMedication),
            ])
            ->execute();
        } catch (Yii\db\IntegrityException $e) {
          continue;
        }

        echo "   > MedicationRequest identifier generated: "
          . ($payloadMedication['identifier_noresep_index'] ?? 'FAILED')
          . "\n";

        print_r(json_encode($payloadMedication));
      }
    }
    // end function generateUgd()
  }

  public function sendTaskRalan(): void
  {
    $taskMedicationRequest = (new Query())
      ->select([
        "smr.id",
        "smr.medication_idIHS",
        "smr.encounter_idIHS",
        "smr.payload",
        "smr.send_status",
        "smr.created_at",
        "smr.updated_at",
        "smr.send_at",
      ])
      ->from('ssht_medication_request smr')
      ->where(['send_status' => 'P'])
      ->orderBy([
        'id' => SORT_ASC
      ])
      ->limit($this->config['medication_cron_limit'])
      ->all($this->dbLocal);

    if (empty($taskMedicationRequest)) {
      echo "[!] Tydac ada data task medicationRequest\n";
      return;
    }

    foreach ($taskMedicationRequest as $key => $record) {

      try {

        $payload = json_decode($record['payload'], true);

        // CHECKING SUDAH TER SYNC MEDICATION belum..
        $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId(
          $payload['local_id']
        );

        if (!$getLocalObt) {

          echo "[-] tydac ada local_id tsb..\n";

          $this->dbLocal->createCommand()->update(
            'ssht_medication_request',
            [
              'send_status' => 'M',
              'send_error_code' => '',
              'send_error_message' =>
              'Obat: ' . $payload['local_id']
                . ' ,dengan identifier_noresep_index: '
                . $payload['identifier_noresep_index']
                . ' ,Perlu mapping Obat (KFA & sync Medication) untuk kirim MedicationRequest..',
              'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )->execute();

          echo "[-] {$payload['identifier_noresep_index']}"
            . " dengan Obat: {$payload['local_id']}"
            . " Perlu dimaping KFA dan Sync Medication..\n";

          continue;
        }

        // SEND TASK to SSHT
        if (
          !$this->debugger->allow(
            context: SshtApiUtil::genDebugContext(
              SshtApiUrl::MEDICATION_REQUEST_CREATE
            ),
            payload: $payload,
          )
        ) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_REQUEST_CREATE,
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

          $this->dbLocal->createCommand()->update(
            'ssht_medication_request',
            [
              'send_status' => 'F',
              'send_error_code' => $response->getStatusCode(),
              'send_error_message' =>
              json_encode($resMedReq['errors'])
                ?? $response->getMessage(),
              'updated_at' => date('Y-m-d H:i:s'),
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )->execute();

          continue;
        }

        $medicationRequest_idIHS =
          $resMedReq['data']['medicationRequest_idIHS'] ?? null;

        sleep(1);

        if ($medicationRequest_idIHS) {

          $MedReqData = $resMedReq['data'];

          $this->dbLocal->createCommand()->update(
            'ssht_medication_request',
            [
              'medicationrequest_idIHS' =>
              $medicationRequest_idIHS,

              'contained' =>
              json_encode($MedReqData['contained']),

              'category_code' =>
              $MedReqData['category_code'],

              'category_display' =>
              $MedReqData['category_display'],

              'category_system' =>
              $MedReqData['category_system'],

              'performer_org_idIHS' =>
              $MedReqData['performer_org_idIHS'],

              'quantity_system' =>
              $MedReqData['quantity_system'],

              'quantity_code' =>
              $MedReqData['quantity_code'],

              'quantity_unit' =>
              $MedReqData['quantity_unit'],

              'quantity_value' =>
              $MedReqData['quantity_value'],

              'authored_on' =>
              $MedReqData['authored_on'],

              'validity_period_start' =>
              $MedReqData['validity_period_start'],

              'validity_period_end' =>
              $MedReqData['validity_period_end'],

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

              'send_status' => 'S',

              'payload' =>
              json_encode($payload),

              'send_error_message' => '',
              'send_error_code' => '',
            ],
            [
              'identifier_noresep_index' =>
              $payload['identifier_noresep_index'],
            ]
          )->execute();

          echo "\nMedicationRequest: "
            . ($medicationRequest_idIHS ?? 'FAILED')
            . "\n";
        }
      } catch (\Throwable $e) {

        $this->dbLocal->createCommand()->update(
          'ssht_medication_request',
          [
            'send_status' => 'F',
            'send_error_code' => $e->getCode(),
            'send_error_message' => $e->getMessage(),
            'updated_at' => date('Y-m-d H:i:s'),
          ],
          [
            'identifier_noresep_index' =>
            $payload['identifier_noresep_index'],
          ]
        )->execute();

        continue;
      }
    }
    // end function sendTaskRalan()
  }
}
