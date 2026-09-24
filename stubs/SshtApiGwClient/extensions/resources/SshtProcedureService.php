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

class SshtProcedureService
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

  public function sendGeneralRalan($tgl_param)
  {
    echo "--- TASK SSHT Procedure General (Ralan): [{$tgl_param}] ---\n";

    try {

      $encounters = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'practition_idIHS',
          'inprogress_start',
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

      if (empty($encounters)) {
        echo "[!] Tydac ada data encounter tanggal {$tgl_param}\n";
        return;
      }

      print_r($encounters);

      foreach ($encounters as $enc) {

        $rm = $enc['subject_rm'];

        $simrs = SshtApiQueryMapping::queryProcedureGeneral(
          $tgl_param,
          $rm
        );

        if (!$simrs) {
          echo "[-] SKIP: RM {$rm} tydac ada ProcedureGeneral\n";
          continue;
        }

        print_r($simrs);

        $pdata = $simrs['procedures'];

        print_r($pdata);

        foreach ($pdata as $p) {

          $payloadProcedure = [
            'encounterIdIHS' => $enc['idIHS'] ?? '',
            'proc_code' => $p['icd9'] ?? '',
            'proc_display' => $p['desc'] ?? '',
            'status' => 'completed',
            'category' => 'general',
            'dok' => $simrs['kode_dokter'] ?? '',
            'rm' => $simrs['rm_pasien'] ?? '',
            'datetime' => $enc['inprogress_start'] ?? ''
          ];

          if (!$this->debugger->allow(
            context: SshtApiUtil::genDebugContext(
              SshtApiUrl::PROCEDURE_CREATE
            ),
            payload: $payloadProcedure,
          )) {
            continue;
          }

          $resProcReq = SshtApiBase::request(
            SshtApiUrl::PROCEDURE_CREATE,
            [
              'json' => $payloadProcedure
            ]
          );

          $resProc = json_decode(
            (string) $resProcReq->getBody(),
            true
          );

          echo "[+] body-response: \n";
          print_r($resProc);

          if (
            $resProcReq->getStatusCode() == 400 &&
            ($resProc['errors']['code'] ?? null) === 'duplicate'
          ) {
            continue;
          }

          $procedureIhsId =
            $resProc['data']['procedure_idIHS'] ?? null;

          sleep(1);

          if ($procedureIhsId) {

            $procData = $resProc['data'];

            $this->dbLocal
              ->createCommand()
              ->insert('ssht_procedure', [
                'procedure_idIHS' => $procedureIhsId,
                'encounter_idIHS' => $enc['idIHS'],
                'code' => $procData['code'],
                'display' => $procData['display'],
                'system' => $procData['system'],
                'category_code' => $procData['category_code'],
                'category_display' => $procData['category_display'],
                'category_system' => $procData['category_system'],
                'subject_idIHS' => $procData['subject_idIHS'],
                'practition_idIHS' => $procData['practition_idIHS'],
                'rm' => $procData['rm'],
                'dok' => $procData['dok'],
                'date' => $procData['date'],
                'status' => $procData['status'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
              ])
              ->execute();

            echo "   > Procedure OK: "
              . ($procedureIhsId ?? 'FAILED')
              . " ({$p['icd9']} - {$p['desc']})\n";

            print_r($procData);
          } else {

            echo " FAILED Procedure";
            sleep(2);
          }
        }
      }
    } catch (Exception $e) {

      echo " ERROR: " . $e->getMessage() . "\n";
      echo $e;

      sleep(5);
    }

    sleep(2);
  }

  public function sendGeneralUgd($tgl_param)
  {
    echo "--- TASK SSHT Procedure General (UGD): [{$tgl_param}] ---\n";

    try {

      $encounters = (new Query())
        ->select([
          'idIHS',
          'subject_rm',
          'practition_idIHS',
          'inprogress_start',
          'class'
        ])
        ->from('ssht_encounter')
        ->where([
          'CAST(inprogress_start AS DATE)' => $tgl_param
        ])
        ->andWhere([
          'class' => 'EMER'
        ])
        ->all($this->dbLocal);

      if (empty($encounters)) {
        echo "[!] Tydac ada data encounter tanggal {$tgl_param}\n";
        return;
      }

      print_r($encounters);

      foreach ($encounters as $enc) {

        $rm = $enc['subject_rm'];

        $simrs = SshtApiQueryMapping::queryProcedureGeneralUgd(
          $tgl_param,
          $rm
        );

        if (!$simrs) {
          echo "[-] SKIP: RM {$rm} tydac ada ProcedureGeneral\n";
          continue;
        }

        print_r($simrs);

        $pdata = $simrs['procedures'];

        print_r($pdata);

        foreach ($pdata as $p) {

          $payloadProcedure = [
            'encounterIdIHS' => $enc['idIHS'] ?? '',
            'proc_code' => $p['icd9'] ?? '',
            'proc_display' => $p['desc'] ?? '',
            'status' => 'completed',
            'category' => 'general',
            'dok' => $simrs['kode_dokter'] ?? '',
            'rm' => $simrs['rm_pasien'] ?? '',
            'datetime' => $enc['inprogress_start'] ?? ''
          ];

          if (!$this->debugger->allow(
            context: SshtApiUtil::genDebugContext(
              SshtApiUrl::PROCEDURE_CREATE
            ),
            payload: $payloadProcedure,
          )) {
            continue;
          }

          $resProcReq = SshtApiBase::request(
            SshtApiUrl::PROCEDURE_CREATE,
            [
              'json' => $payloadProcedure
            ]
          );

          $resProc = json_decode(
            (string) $resProcReq->getBody(),
            true
          );

          echo "[+] body-response: \n";
          print_r($resProc);

          if (
            $resProcReq->getStatusCode() == 400 &&
            ($resProc['errors']['code'] ?? null) === 'duplicate'
          ) {
            continue;
          }

          $procedureIhsId =
            $resProc['data']['procedure_idIHS'] ?? null;

          sleep(1);

          if ($procedureIhsId) {

            $procData = $resProc['data'];

            $this->dbLocal
              ->createCommand()
              ->insert('ssht_procedure', [
                'procedure_idIHS' => $procedureIhsId,
                'encounter_idIHS' => $enc['idIHS'],
                'code' => $procData['code'],
                'display' => $procData['display'],
                'system' => $procData['system'],
                'category_code' => $procData['category_code'],
                'category_display' => $procData['category_display'],
                'category_system' => $procData['category_system'],
                'subject_idIHS' => $procData['subject_idIHS'],
                'practition_idIHS' => $procData['practition_idIHS'],
                'rm' => $procData['rm'],
                'dok' => $procData['dok'],
                'date' => $procData['date'],
                'status' => $procData['status'],
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
              ])
              ->execute();

            echo "   > Procedure OK: "
              . ($procedureIhsId ?? 'FAILED')
              . " ({$p['icd9']} - {$p['desc']})\n";

            print_r($procData);
          } else {

            echo " FAILED Procedure";
            sleep(2);
          }
        }
      }
    } catch (Exception $e) {

      echo " ERROR: " . $e->getMessage() . "\n";
      echo $e;

      sleep(5);
    }

    sleep(2);
  }
}
