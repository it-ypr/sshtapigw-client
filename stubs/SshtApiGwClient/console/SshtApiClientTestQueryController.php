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
 * SshtApiClientTestController
 *
 * SshtApiClientTestController iki untuk test query di SshtApiQueryMapping
 */
class SshtApiClientTestQueryController extends Controller
{
  /**
   * Run: php yii ssht-test-query/test-hello
   */
  public function actionTestHello()
  {
    $config = SshtApiBase::getConfig();
    echo "Hi.. \n";
    echo "config load: " . json_encode($config) . "\n";
    // return ExitCode::OK;
  }

  /**
   * Run: php yii ssht-test-query/encounter-ralan-test 2025-05-01 rm
   */
  public static function actionEncounterRalanTest($tgl_param)
  {
    $dataEncounter = SshtApiQueryMapping::queryEncounterRalanSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";
    print_r($dataEncounter[0]);
  }

  /**
   * Run: php yii ssht-test-query/lab-ralan-test 2025-05-01 rm
   */
  public static function actionLabRalanTest(string $tgl_param, string $rm_param)
  {
    $dataLabRalan = SshtApiQueryMapping::queryLabRalan($tgl_param, $rm_param);

    if (empty($dataLabRalan)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
      // return ExitCode::OK;
    }

    echo "Ditemukan " . count($dataLabRalan) . " data.\n";
    print_r($dataLabRalan);
    // print_r($dataLabRalan[0]);
  }

  /**
   * Test output data observation lab ralan
   * Example command: php yii ssht-test-query/obs-lab-ralan-test 2025-09-29 320904 58410-2
   */
  public static function actionObsLabRalanTest(
    string $tgl_param,
    string $rm_param,
    string $loinc_order
  ) {
    $dataObsLabRalan = SshtApiQueryMapping::getObservationLabLocalRalan(
      $tgl_param,
      $rm_param,
      $loinc_order
    );

    if (empty($dataObsLabRalan)) {
      echo "Data tidak ditemukan untuk tanggal $tgl_param\n";
    }

    echo "Ditemukan " . count($dataObsLabRalan) . " data.\n";
    print_r($dataObsLabRalan);
  }

  /**
   * php yii ssht-test-query/procedure-general-test 2025-09-27 055129
   */
  public function actionProcedureGeneralTest(string $tanggal, string $rm)
  {
    $simrsobs = SshtApiQueryMapping::queryProcedureGeneral($tanggal, $rm);
    if ($simrsobs) {
      print_r($simrsobs);
    } else {
      echo "\ntidak ditemukan data procedure.\n";
    }
  }

  /**
   * php yii ssht-test-query/send-observation-ralan-test 2026-05-01 rm
   */
  public function actionSendObservationRalanTest($tanggal, $rm)
  {
    $simrsobs = SshtApiQueryMapping::queryObservationRalan($tanggal, $rm);
    if ($simrsobs) {
      print_r($simrsobs);
    } else {
      echo "\ntidak ditemukan data observasi KO.\n";
    }
  }
}
