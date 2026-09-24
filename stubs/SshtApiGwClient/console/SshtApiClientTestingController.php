<?php

namespace common\services\SshtApiGwClient\console;

use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;
use yii\console\Controller;
use yii\db\Query;
use yii\helpers\Json;
use Exception;
use GuzzleHttp\Client;
use Yii;

/**
 * SshtApiClientTestingController
 *
 * SshtApiClientTestingController iki untuk test send experimental module/resource..
 */
class SshtApiClientTestingController extends Controller
{
  /**
   * php yii ssht-api-client-testing/test-wrapper-send-medication-ralan-single 2026-05-01 $rm
   */
  public function actionTestWrapperSendMedicationRalanSingle(string $tgl_param, string $rm_param): void
  {
    $this->actionTestSendMedicationRequestRalanSingle($tgl_param, $rm_param);
    // $this->actionTestSendMedicationDispenseRalanSingle($tgl_param, $rm_param);
  }

  /**
   * php yii ssht-api-client-testing/test-send-medication-request-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationRequestRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

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
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['subject_rm' => $rm])
        ->andWhere(['class' => 'AMB'])
        ->one($dbLocal);

      if (empty($encounter)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r($encounter);

      $rm = $encounter['subject_rm'];
      $encounterIdIHS = $encounter['idIHS'];

      $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

      if (!$simrs) {
        $this->stdout("[-] SKIP: RM $rm tydac ada ProcedureGeneral\n");
        return;
        // continue;
      }

      print_r($simrs);

      foreach ($simrs as $key => $obt) {

        $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

        $payloadMedication = [
          "encounter_idIHS" => $encounterIdIHS,
          "patient_idIHS" => $encounter['subject_idIHS'],
          "patient_nama" => $encounter['subject_nama'],
          "rm" => $encounter['subject_rm'],
          "dok" => $encounter['practition_lokalid'],
          "practition_idIHS" => $encounter['practition_idIHS'],
          "practition_nama" => $encounter['practition_nama'],
          "inprogress_end" => $encounter['inprogress_end'],
          "identifier_noresep" => $identifier_resep->identifier_noresep,
          "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
          "local_id" => (string) $obt['id_local'],
          "kfa_code" => $obt['kfa_code'],
          "kfa_display" => $obt['kfa_display'],
          "kfa_bza" => json_decode($obt['kfa_bza'], true),
          "kfa_form" => json_decode($obt['kfa_form'], true),
          "kfa_route" => json_decode($obt['kfa_route'], true),
          "jumlah" => (string) trim($obt['jumlah']),
          "kali" => (string) trim($obt['kali']),
          "hari" => (string) trim($obt['hari'])
        ];

        //
        // print_r(json_encode($payloadMedication));
        // exit;


        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_REQUEST_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationRequest_idIHS = $resMedReq['data']['medicationRequest_idIHS'] ?? null;
        sleep(1);

        if ($medicationRequest_idIHS) {
          $MedReqData = $resMedReq['data'];

          Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
            'medicationrequest_idIHS' => $medicationRequest_idIHS, // uuid
            'encounter_idIHS' => $encounterIdIHS, // uuid

            'identifier_noresep' => $MedReqData["identifier_noresep"],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code' => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm' => $MedReqData['rm'], // string:7
            'subject_idIHS' => $MedReqData['subject_idIHS'], // string:30
            'dok' => $MedReqData['dok'], // string:14
            'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30
            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'], // text
            'expected_supply_duration' => $MedReqData['expected_supply_duration'], // text
            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'], // text

            'performer_org_idIHS' => $MedReqData['performer_org_idIHS'], // string:30 - organisasi farmasi ralan

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:30
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20
            'authored_on' => $MedReqData['authored_on'], // date (y-m-d H:i:s) -> nullable true
            'validity_period_start' => $MedReqData['validity_period_start'], // date (y-m-d H:i:s)
            'validity_period_end' => $MedReqData['validity_period_end'], // date (y-m-d H:i:s)
            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status' => $MedReqData['status'], // string:30
            'local_id' => $MedReqData['local_id'],

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $simrs['ihs_petugas'], // string:30
            'petugas_nama' => $simrs['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $simrs['ihs_petugas_ambil'], // string:30
            'petugas_ambil_nama' => $simrs['petugas_ambil_nama'], // string:30

            // timestamp
            'created_at' => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at' => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationRequest OK: " . ($medicationRequest_idIHS ?? 'FAILED') . "\n";
          print_r($MedReqData);
        } else {
          echo " FAILED MedicationRequest";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function actionTestQueryMedicationRequestRalanSingle
  }

  /**
   * php yii ssht-api-client-testing/test-send-medication-dispense-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationDispenseRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      // payload dari MedicationRequest yang sudah dikirim sebelumnya..
      $medicationRequest = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationRequest) {
        $this->stdout("[-] SKIP: RM $rm tydac ada MedicationRequest\n");
        return;
        // continue;
      }

      print_r($medicationRequest);

      foreach ($medicationRequest as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
          // mapping dari ref
          "jumlah" => (string) $obt['quantity_value'],
          "kali" => (string) $kali,
          "hari" => (string) $period,
          // location hardcode untuk test
          "location_idIHS" => $config['location_medication_ralan_ihs'],
          "location_nama" => $config['location_medication_ralan_display'],
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_DISPENSE_CREATE),
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_DISPENSE_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationDispense_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationDispense_idIHS) {
          $MedReqData = $resMedReq['data'];

          Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
            'medicationdispense_idIHS' => $medicationDispense_idIHS, // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid
            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'identifier_noresep' => $MedReqData['identifier_noresep'],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code' => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm' => $MedReqData['rm'], // string:7
            'subject_idIHS' => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok' => $MedReqData['dok'], // string:14
            'requester_idIHS' => $obt['requester_idIHS'], // string:30

            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'] ?? "", // ini tidak ada di medicationDispense
            'expected_supply_duration' => $MedReqData['expected_supply_duration'] ?? "", // harusnya days_supply 

            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'] ?? "", //  fix besok hanya ada di medicationRequest

            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:20
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20

            'when_prepared' => $MedReqData['when_prepared'], // date (y-m-d H:i:s) -> nullable true
            'when_handed_over' => $MedReqData['when_handed_over'], // date (y-m-d H:i:s) -> nullable true

            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status' => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at' => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at' => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationDispense OK: " . ($medicationDispense_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq); // kudumen iki hm..
        } else {
          echo " FAILED MedicationDispense";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function 
  }

  /**
   * php yii ssht-api-client-testing/test-send-medication-administration-ralan-single 2026-05-01 $rm
   */
  public function actionTestSendMedicationAdministrationRalanSingle(string $tgl_param, string $rm)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      $medicationPatient = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id',
          'smd.medicationdispense_idIHS'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->leftJoin('ssht_medication_dispense smd', 'smr.medicationrequest_idIHS = smd.medicationdispense_idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationPatient) {
        $this->stdout("[-] Info: $rm pada tanggal $tgl_param tydac ada data MedicationRequest & MedicationDispense\n");
        return;
      }

      print_r($medicationPatient);

      foreach ($medicationPatient as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medication_idIHS" => $getRefObtKfa['medication_idIHS'],
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_ADMINISTRATION_CREATE),
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_ADMINISTRATION_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationAdministration_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationAdministration_idIHS) {
          $MedReqData = $resMedReq['data'];

          Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_administration', [
            'medicationadministration_idIHS' => $medicationAdministration_idIHS, // uuid
            // 'medicationdispense_idIHS' => $obt['medicationdispense_idIHS'], // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid

            'medication_idIHS' => $MedReqData['medication_idIHS'], // uuid

            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'rm' => $MedReqData['rm'], // string:7
            'subject_idIHS' => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok' => $MedReqData['dok'], // string:14

            // iki bagian MedicationRequest.dispenseRequest:
            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            // end bagian MedicationRequest.dispenseRequest:
            // 'dosage_instruction' => $MedReqData['dosage_instruction'] ?? "", // text -> nullable true,

            "reasonCode" => $MedReqData["reasonCode"],

            'status' => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at' => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at' => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > medicationAdministration OK: " . ($medicationAdministration_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq);
          // end if $medicationAdministration_idIHS
        } else {
          echo " FAILED medicationAdministration";
          sleep(2);
        }
        sleep(3);
        // endforeach   $medicationPatient
      }
    } catch (Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
  }

  /**
   * php yii ssht-api-client-testing/send-medication-request-ralan 2026-05-01
   */
  public function actionSendMedicationRequestRalan(string $tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

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
        ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
        ->andWhere(['class' => 'AMB'])
        ->all($dbLocal);

      if (empty($encounter)) {
        $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
        return;
      }

      print_r("Data array encounter:\n");
      print_r($encounter[0]);
      print_r($encounter[1]);
      print_r("...\n");

      foreach ($encounter as $key => $record) {

        $rm = $record['subject_rm'];
        $encounterIdIHS = $record['idIHS'];

        $simrs = SshtApiQueryMapping::queryMedicationRalan(tgl_param: $tgl_param, rm_param: $rm);

        if (!$simrs) {
          $this->stdout("[-] SKIP: RM $rm tydac ada ProcedureGeneral\n");
          return;
          // continue;
        }

        print_r($simrs);

        foreach ($simrs as $key => $obt) {

          $identifier_resep = SshtApiUtil::genIdentifierResepMedication($tgl_param, $obt['resep'], $key);

          $payloadMedication = [
            "encounter_idIHS" => $encounterIdIHS,
            "patient_idIHS" => $encounter['subject_idIHS'],
            "patient_nama" => $encounter['subject_nama'],
            "rm" => $encounter['subject_rm'],
            "dok" => $encounter['practition_lokalid'],
            "practition_idIHS" => $encounter['practition_idIHS'],
            "practition_nama" => $encounter['practition_nama'],
            "inprogress_end" => $encounter['inprogress_end'],
            "identifier_noresep" => $identifier_resep->identifier_noresep,
            "identifier_noresep_index" => $identifier_resep->identifier_noresep_index,
            "local_id" => (string) $obt['id_local'],
            "kfa_code" => $obt['kfa_code'],
            "kfa_display" => $obt['kfa_display'],
            "kfa_bza" => json_decode($obt['kfa_bza'], true),
            "kfa_form" => json_decode($obt['kfa_form'], true),
            "kfa_route" => json_decode($obt['kfa_route'], true),
            "jumlah" => (string) trim($obt['jumlah']),
            "kali" => (string) trim($obt['kali']),
            "hari" => (string) trim($obt['hari'])
          ];

          //
          // print_r(json_encode($payloadMedication));
          // exit;


          if (
            !$debugger->allow(
              context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_REQUEST_CREATE),
              payload: $payloadMedication,
            )
          ) {
            continue;
          }

          $response = SshtApiBase::request(
            SshtApiUrl::MEDICATION_REQUEST_CREATE,
            [
              'json' => $payloadMedication
            ]
          );

          $resMedReq = json_decode((string) $response->getBody(), true);

          // print_r($resProcReq->getBody());
          $this->stdout("[+] body-response: \n");
          print_r($resMedReq);

          if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
            continue;
          }

          $medicationRequest_idIHS = $resMedReq['data']['medicationRequest_idIHS'] ?? null;
          sleep(1);

          if ($medicationRequest_idIHS) {
            $MedReqData = $resMedReq['data'];

            Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_request', [
              'medicationrequest_idIHS' => $medicationRequest_idIHS, // uuid
              'encounter_idIHS' => $encounterIdIHS, // uuid

              'identifier_noresep' => $MedReqData["identifier_noresep"],
              'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

              'contained' => $MedReqData['contained'], // text

              'category_code' => $MedReqData['category_code'], // string:20
              'category_display' => $MedReqData['category_display'], // string:30
              'category_system' => $MedReqData['category_system'], // string:191

              'rm' => $MedReqData['rm'], // string:7
              'subject_idIHS' => $MedReqData['subject_idIHS'], // string:30
              'dok' => $MedReqData['dok'], // string:14
              'requester_idIHS' => $MedReqData['requester_idIHS'], // string:30
              // iki bagian MedicationRequest.dispenseRequest:
              'dispense_interval' => $MedReqData['dispense_interval'], // text
              'expected_supply_duration' => $MedReqData['expected_supply_duration'], // text
              'number_repeat_allowed' => $MedReqData['number_repeat_allowed'], // text

              'performer_org_idIHS' => $MedReqData['performer_org_idIHS'], // string:30 - organisasi farmasi ralan

              'quantity_system' => $MedReqData['quantity_system'], // string:191
              'quantity_code' => $MedReqData['quantity_code'], // string:30
              'quantity_unit' => $MedReqData['quantity_unit'], // string:30
              'quantity_value' => $MedReqData['quantity_value'], // string:20
              'authored_on' => $MedReqData['authored_on'], // date (y-m-d H:i:s) -> nullable true
              'validity_period_start' => $MedReqData['validity_period_start'], // date (y-m-d H:i:s)
              'validity_period_end' => $MedReqData['validity_period_end'], // date (y-m-d H:i:s)
              // end bagian MedicationRequest.dispenseRequest:
              'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
              'status' => $MedReqData['status'], // string:30
              'local_id' => $MedReqData['local_id'],

              // untuk mempermudah mapping lokal (trace)
              'petugas_idIHS' => $simrs['ihs_petugas'], // string:30
              'petugas_nama' => $simrs['petugas_nama'], // string:30
              'petugas_ambil_idIHS' => $simrs['ihs_petugas_ambil'], // string:30
              'petugas_ambil_nama' => $simrs['petugas_ambil_nama'], // string:30

              // timestamp
              'created_at' => date('Y-m-d H:i:s'), //  timestamp nullable true 
              'updated_at' => date('Y-m-d H:i:s') // timestamp nullable true
            ])->execute();

            echo "   > MedicationRequest OK: " . ($medicationRequest_idIHS ?? 'FAILED') . "\n";
            print_r($MedReqData);
          } else {
            echo " FAILED MedicationRequest";
            sleep(3);
          }
          sleep(2);
          //end foreach $encounter record...
        }
        sleep(4);
        // end for each obt 
      }
    } catch (Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function actionTestQueryMedicationRequestRalanSingle
  }

  /**
   * php yii ssht-api-client-testing/send-medication-dispense-ralan 2026-05-01
   */
  public function actionSendMedicationDispenseRalan(string $tgl_param)
  {
    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    try {

      // payload dari MedicationRequest yang sudah dikirim sebelumnya..
      $medicationRequest = (new Query())
        ->select([
          'smr.medicationrequest_idIHS',
          'se.idIHS as encounter_idIHS',
          'se.subject_rm',
          'se.subject_idIHS',
          'se.subject_nama',
          'smr.requester_idIHS',
          'se.practition_lokalid',
          'se.practition_nama',
          'smr.petugas_idIHS',
          'smr.petugas_nama',
          'smr.petugas_ambil_idIHS',
          'smr.petugas_ambil_nama',
          'se.inprogress_end',
          'smr.contained',
          'smr.dosage_instruction',
          'smr.quantity_value',
          'smr.identifier_noresep',
          'smr.identifier_noresep_index',
          'smr.lokal_id'
        ])
        ->from('ssht_medication_request smr')
        ->leftJoin('ssht_encounter se', 'smr.encounter_idIHS = se.idIHS')
        ->where(['CAST(authored_on AS DATE)' => $tgl_param])
        ->all($dbLocal);

      if (!$medicationRequest) {
        $this->stdout("[-] $tgl_param tydac ada MedicationRequest\n");
        return;
        // continue;
      }

      print_r($medicationRequest);

      foreach ($medicationRequest as $key => $obt) {

        $localid = $obt['lokal_id'];

        $getRefObtKfa = SshtApiQueryMapping::getRefObatByLocalId($localid);

        $dosageinstruc = json_decode($obt['dosage_instruction'], true);

        $kali = $dosageinstruc[0]['timing']['repeat']['frequency'];
        $period = $dosageinstruc[0]['timing']['repeat']['period'];
        $periodUnit = $dosageinstruc[0]['timing']['repeat']['periodUnit'];

        if (!$getRefObtKfa) {
          continue;
        }

        $payloadMedication = [
          "medicationRequest_idIHS" => $obt['medicationrequest_idIHS'],
          "encounter_idIHS" => $obt['encounter_idIHS'],
          "patient_idIHS" => $obt['subject_idIHS'],
          "patient_nama" => $obt['subject_nama'],
          "rm" => $obt['subject_rm'],
          "dok" => $obt['practition_lokalid'],
          "performer_idIHS" => $obt['petugas_idIHS'],
          "performer_nama" => $obt['petugas_nama'],
          "inprogress_end" => $obt['inprogress_end'],
          "identifier_noresep" => $obt['identifier_noresep'],
          "identifier_noresep_index" => $obt['identifier_noresep_index'],
          // mapping dari ref
          "local_id" => (string) $localid,
          "kfa_code" => $getRefObtKfa['kfa_code'],
          "kfa_display" => $getRefObtKfa['kfa_display'],
          "kfa_bza" => json_decode($getRefObtKfa['kfa_bza'], true),
          "kfa_form" => json_decode($getRefObtKfa['kfa_form'], true),
          "kfa_route" => json_decode($getRefObtKfa['kfa_route'], true),
          // mapping dari ref
          "jumlah" => (string) $obt['quantity_value'],
          "kali" => (string) $kali,
          "hari" => (string) $period,
          // location hardcode untuk test
          "location_idIHS" => $config['location_medication_ralan_ihs'],
          "location_nama" => $config['location_medication_ralan_display'],
        ];

        // echo "\n";
        // print_r(json_encode($payloadMedication));
        // exit;

        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::MEDICATION_DISPENSE_CREATE),
            payload: $payloadMedication,
          )
        ) {
          continue;
        }

        $response = SshtApiBase::request(
          SshtApiUrl::MEDICATION_DISPENSE_CREATE,
          [
            'json' => $payloadMedication
          ]
        );

        $resMedReq = json_decode((string) $response->getBody(), true);

        // print_r($resProcReq->getBody());
        $this->stdout("[+] body-response: \n");
        print_r($resMedReq);

        if ($response->getStatusCode() == 400 && $resMedReq['errors']['code'] == 'duplicate') {
          continue;
        }

        $medicationDispense_idIHS = $resMedReq['data']['medicationDispense_idIHS'] ?? null;
        sleep(1);

        if ($medicationDispense_idIHS) {
          $MedReqData = $resMedReq['data'];

          Yii::$app->sshtAPIdb->createCommand()->insert('ssht_medication_dispense', [
            'medicationdispense_idIHS' => $medicationDispense_idIHS, // uuid
            'medicationrequest_idIHS' => $MedReqData['medicationRequest_idIHS'], // uuid
            'encounter_idIHS' => $MedReqData['encounter_idIHS'], // uuid

            'identifier_noresep' => $MedReqData['identifier_noresep'],
            'identifier_noresep_index' => $MedReqData['identifier_noresep_index'],

            'contained' => $MedReqData['contained'], // text

            'category_code' => $MedReqData['category_code'], // string:20
            'category_display' => $MedReqData['category_display'], // string:30
            'category_system' => $MedReqData['category_system'], // string:191

            'rm' => $MedReqData['rm'], // string:7
            'subject_idIHS' => $MedReqData['subject_idIHS'], // string:30

            // untuk membantu local map
            'dok' => $MedReqData['dok'], // string:14
            'requester_idIHS' => $obt['requester_idIHS'], // string:30

            // iki bagian MedicationRequest.dispenseRequest:
            'dispense_interval' => $MedReqData['dispense_interval'] ?? "", // ini tidak ada di medicationDispense
            'expected_supply_duration' => $MedReqData['expected_supply_duration'] ?? "", // harusnya days_supply 

            'number_repeat_allowed' => $MedReqData['number_repeat_allowed'] ?? "", //  fix besok hanya ada di medicationRequest

            'performer_idIHS' => $MedReqData['performer_idIHS'], // string:30

            'quantity_system' => $MedReqData['quantity_system'], // string:191
            'quantity_code' => $MedReqData['quantity_code'], // string:20
            'quantity_unit' => $MedReqData['quantity_unit'], // string:30
            'quantity_value' => $MedReqData['quantity_value'], // string:20

            'when_prepared' => $MedReqData['when_prepared'], // date (y-m-d H:i:s) -> nullable true
            'when_handed_over' => $MedReqData['when_handed_over'], // date (y-m-d H:i:s) -> nullable true

            // end bagian MedicationRequest.dispenseRequest:
            'dosage_instruction' => $MedReqData['dosage_instruction'], // text -> nullable true,
            'status' => $MedReqData['status'], // string:30

            // untuk mempermudah mapping lokal (trace)
            'petugas_idIHS' => $MedReqData['performer_idIHS'], // string:30
            'petugas_nama' => $obt['petugas_nama'], // string:30
            'petugas_ambil_idIHS' => $obt['petugas_ambil_idIHS'], // string:30
            'petugas_ambil_nama' => $obt['petugas_ambil_nama'], // string:30

            'created_at' => date('Y-m-d H:i:s'), //  timestamp nullable true 
            'updated_at' => date('Y-m-d H:i:s') // timestamp nullable true
          ])->execute();

          echo "   > MedicationDispense OK: " . ($medicationDispense_idIHS ?? 'FAILED') . "\n";
          // print_r($MedReqData);-> array to string kamprett
          print_r($resMedReq); // kudumen iki hm..
        } else {
          echo " FAILED MedicationDispense";
          sleep(2);
        }

        sleep(3);
        // end for each obt 
      }
    } catch (Exception $e) {
      echo " ERROR: " . $e->getMessage() . "\n";
      echo "$e";
      sleep(3);
    }
    // end function 
  }

  /**
   * Run Cron: php yii ssht-api-client-testing/send-service-request-lab-dan-speciment-ralan-single 2026-05-01 rm
   */
  public function actionSendServiceRequestLabDanSpecimentRalanSingle(
    string $tgl_param,
    string $rm
  ) {
    $class = 'AMB';

    $dbLocal = Yii::$app->sshtAPIdb;

    $config = SshtApiBase::getConfig();

    $debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $encounters = (new Query())
      ->select(['idIHS', 'subject_rm', 'practition_lokalid', 'inprogress_start', 'inprogress_end', 'class'])
      ->from('ssht_encounter')
      ->where(['CAST(inprogress_start AS DATE)' => $tgl_param])
      ->andWhere(['subject_rm' => $rm])
      ->andWhere(['class' => $class])
      ->all($dbLocal);

    if (empty($encounters)) {
      $this->stdout("[!] Tydac ada data encounter tanggal {$tgl_param}\n");
      return;
    }

    foreach ($encounters as $enc) {
      $rm = $enc['subject_rm'];

      $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm);

      if (!$dataLabRalan || empty($dataLabRalan)) {
        $this->stdout("[-] SKIP: RM $rm tydac ada order Lab\n");
        continue;
      }

      // print_r($dataLabRalan);

      $dataPreReqLab = $dataLabRalan["prereq"];

      foreach ($dataLabRalan['lab_result'] as $lab) {

        // payload ServiceRequestLab
        $payload = [
          "sampleID" => $lab["svc_req"]["service_req_code"],
          "category" => 'lab', // snomed code from (lab)
          "serviceReqCode" => $lab["svc_req"]["loinc_order_code"], // loinc code
          "serviceReqDisplay" => $lab["svc_req"]["loinc_order_display"], // loinc display
          "reason" => $lab["svc_req"]["nama_lokal"], // text
          "encounter_idIHS" => $enc["idIHS"],
          "dokter" => $enc["practition_lokalid"],
          "rm" => $enc["subject_rm"],
          "petugaslab_idIHS" => $dataPreReqLab["petugas_ihs"],
          "petugaslab_nama" => $dataPreReqLab["petugas_nama"]
        ];

        print_r($payload);

        $now = date('Y-m-d H:i:s');

        // $dbLocal->createCommand()->insert('ssht_servicerequest', [
        //   'servicerequest_idIHS' => '',
        //   'encounter_idIHS' => $payload['encounter_idIHS'],
        //   'rm' => $rm,
        //   'patient_idIHS' => $enc['subject_idIHS'] ?? null,
        //   'petugas_idIHS' => $payload['petugas_idIHS'],
        //   'petugas_nama' => $payload['petugas_nama'],
        //   'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
        //   'dok' => $payload['dokter'] ?? null,
        //   'date' => $enc['inprogress_end'],
        //   'created_at' => $now,
        //   'updated_at' => $now,
        // ])->execute();
        //
        // $idSr = $dbLocal->getLastInsertID();

        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::SERVICE_REQUEST_CREATE_LAB),
            payload: $payload,
          )
        ) {
          continue;
        }

        // 3. Kirim via Wrapper
        $response = SshtApiBase::request(
          SshtApiUrl::SERVICE_REQUEST_CREATE_LAB,
          ['json' => $payload]
        );

        $result = json_decode((string) $response->getBody(), true);
        print_r(json_encode($result));

        if (isset($result['status']) && ($result['status'] == 'true')) {
          $data_api = $result['data'] ?? [];
          $sr_id_ihs = $data_api['servicerequest_idIHS'] ?? null;

          // 4. Save to Local DB
          $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          $dbLocal->createCommand()->insert(
            'ssht_servicerequest',
            [
              'servicerequest_idIHS' => $sr_id_ihs,
              'encounter_idIHS' => $enc['idIHS'],
              'acsn' => $data_api['acsn'] ?? null,
              'category_code' => $data_api['category_code'] ?? null,
              'category_display' => $data_api['category_display'] ?? null,
              'code' => $data_api['code'] ?? null,
              'display' => $data_api['display'] ?? null,
              'perihal' => $data_api['perihal'] ?? null,
              'rm' => $rm,
              'patient_idIHS' => $data_api['patient_idIHS'] ?? null,
              'petugas_idIHS' => $payload['petugaslab_idIHS'],
              'petugas_nama' => $payload['petugaslab_nama'],
              'dokter_request_idIHS' => $data_api['dokter_request_idIHS'] ?? null,
              'dok' => $data_api['dok'] ?? null,
              'date' => $data_api['date'],
              'status' => 'active',
              'created_at' => $now,
              'updated_at' => $now,
              'srid' => $data_api['srid'],

              // 'payload' => json_encode($payload),
              // 'send_status' => 'S',
            ]
            // ],
            // [
            //   'id' => $idSr,
            // ]
          )->execute();
        } else {
          // $now = date('Y-m-d H:i:s');
          // $dbLocal->createCommand()->update(
          //   'ssht_servicerequest',
          //   [
          //     'payload' => json_encode($payload),
          //     'send_status' => 'F',
          //     'send_error_code' => $response->getStatusCode(),
          //     'send_error_message' => isset($result["errors"]) ? json_encode($result['errors']) : $response->getMessage(),
          //     'updated_at' => $now,
          //   ],
          //   [
          //     'id' => $idSr,
          //   ]
          // )->execute();
          continue;
        }

        // SPECIMENT

        $payloadSpeciment = [
          "sampleID" => $lab["specimen"]["sample_id"],
          "servicerequest_idIHS" => $sr_id_ihs,
          "encounter_idIHS" => $enc["idIHS"],
          "speciment_code" => $lab["specimen"]["specimen_code"],
          "speciment_display" => $lab["specimen"]["specimen_display"],
          "sampling_method" => $lab["specimen"]["sampling_method"],
          "rm" => $rm,
          "dok" => $enc['practition_lokalid'],
        ];

        $cekSpeciment = (new Query())
          ->select([
            'ssp.id',
            'ssp.speciment_idIHS',
            'ssp.servicerequest_idIHS',
            'ssp.lokal_sampleID_testID',
            'ssp.method_code',
            'ssp.method_display',
            'ssp.code',
            'ssp.display',
            'ssp.date',
            'ssp.status',
          ])
          ->from('ssht_speciment ssp')
          ->where(['ssp.lokal_sampleID_testID' => $payloadSpeciment])
          ->exists($dbLocal);

        if ($cekSpeciment) {
          $this->stdout("[+] Skip Send cuz Duplicate Speciment detected, Rm: {$rm}, lokal_sampleID_testID: {$payloadSpeciment['sampleID']} \n");
          continue;
        }

        if (
          !$debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::SPECIMENT_CREATE),
            payload: $payloadSpeciment,
          )
        ) {
          continue;
        }

        $this->sendSpecimentRalanSingle(
          $rm,
          $enc,
          $payload,
          $lab,
          $dataLabRalan,
          $payloadSpeciment,
          $sr_id_ihs
        );
      }
    }
  }
}
