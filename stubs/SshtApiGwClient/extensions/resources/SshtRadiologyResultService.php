<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use Yii;
use yii\db\Query;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\util\SshtApiUtil;

class SshtRadiologyResultService
{
  private $dbLocal;
  private SshtApiDebugger $debugger;
  private array $config;

  private SshtObservationService $observation;
  private SshtDiagnosticReportService $diagnosticReport;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;

    $this->config = SshtApiBase::getConfig();

    $this->debugger = new SshtApiDebugger(
      enabled: $this->config['debug'] ?? false
    );

    $this->observation = new SshtObservationService();
    $this->diagnosticReport = new SshtDiagnosticReportService();
  }

  public function sendRalan(string $tgl_param): void
  {
    $this->send(
      tgl_param: $tgl_param,
      encounterClass: 'AMB',
      label: 'Ralan'
    );
  }

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
    echo "--- TASK SSHT Observation & DiagnosticReport Radio ({$label}): [{$tgl_param}] ---\n";

    try {
      /*
       * Ambil ImagingStudy yang sudah tersimpan
       * di local SSHT database.
       */
      $respImagingReq = SshtApiBase::request(
        SshtApiUrl::IMAGINGSTUDY_GET_BYDATE,
        [
          'query' => [
            'date' => $tgl_param
          ]
        ]
      );

      $items = $respImagingReq['data'] ?? [];

      if (empty($items)) {
        $this->stdout(
          "[!] Gak ada data ImagingStudy untuk tanggal {$tgl_param}\n"
        );
        return;
      }

      foreach ($items as $master) {
        $imgIdIhs = $master['idIHS'] ?? null;

        if (!$imgIdIhs) {
          continue;
        }

        /*
         * Ambil detail ImagingStudy.
         */
        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::IMAGINGSTUDY_GET
          ),
          payload: [
            'imagingstudy_idIHS' => $imgIdIhs
          ],
        )) {
          continue;
        }

        $respDetail = SshtApiBase::request(
          SshtApiUrl::IMAGINGSTUDY_GET,
          [
            'query' => [
              'id' => $imgIdIhs
            ]
          ]
        );

        $detailData = $respDetail['data'] ?? [];

        if (empty($detailData)) {
          continue;
        }

        $srIdIhs =
          $detailData['servicerequest_idIHS'] ?? null;

        if (!$srIdIhs) {
          continue;
        }

        $acsnFull =
          $detailData['acsn'] ?? '';

        $noradio = strpos($acsnFull, '-') !== false
          ? explode('-', $acsnFull)[0]
          : $acsnFull;

        /*
         * Pastikan ServiceRequest ini memang
         * milik encounter sesuai workflow.
         */
        $serviceRequestExists = (new Query())
          ->from('ssht_servicerequest')
          ->leftJoin(
            'ssht_encounter se',
            'ssht_servicerequest.encounter_idIHS = se.idIHS'
          )
          ->where([
            'ssht_servicerequest.category_display' => 'Imaging',
            'ssht_servicerequest.category_code' => '363679005',
            'ssht_servicerequest.servicerequest_idIHS' => $srIdIhs,
            'se.class' => $encounterClass,
          ])
          ->exists($this->dbLocal);

        if (!$serviceRequestExists) {
          continue;
        }

        /*
         * Ambil hasil expertise radiologi dari SIMRS.
         */
        $row =
          SshtApiQueryMapping::queryObservationDanDiagnosticReportSimrsRadio(
            $noradio
          );

        if (
          empty($row) ||
          empty($row['hasil'])
        ) {
          $this->stdout(
            "[!] Hasil radiologi tidak ditemukan " .
              "untuk NORADIO {$noradio}\n"
          );
          continue;
        }

        /*
         * Parse hasil RTF:
         *
         * $obsText         = bagian hasil/deskripsi
         * $impressionText  = bagian impression/kesimpulan
         */
        [
          $obsText,
          $impressionText
        ] = SshtApiUtil::parseExpertiseRdHasilRtf(
          $row['hasil']
        );

        /*
         * =====================================================
         * OBSERVATION
         * =====================================================
         */
        if (!empty($obsText)) {
          $existsObservation =
            $this->observation->existsRadio(
              rm: $row['rm'],
              tgl_param: $tgl_param,
              valueString: $obsText
            );

          if ($existsObservation) {
            $this->stdout(
              "  [SKIP] Observation sudah ada " .
                "untuk RM {$row['rm']}\n"
            );
          } else {
            try {
              $this->observation->createRadio(
                servicerequestIdIhs: $srIdIhs,
                imagingstudyIdIhs: $imgIdIhs,
                rm: $row['rm'],
                valueString: $obsText
              );

              $this->stdout(
                "  [OK] Observation berhasil dibuat " .
                  "untuk RM {$row['rm']}\n"
              );
            } catch (Exception $e) {
              $this->stdout(
                "  [!] Observation error: " .
                  $e->getMessage() . "\n"
              );
            }
          }
        }

        /*
         * =====================================================
         * DIAGNOSTIC REPORT
         * =====================================================
         */
        if (!empty($impressionText)) {
          $existsDiagnosticReport =
            $this->diagnosticReport->existsRadio(
              rm: $row['rm'],
              tgl_param: $tgl_param,
              servicerequestIdIhs: $srIdIhs
            );

          if ($existsDiagnosticReport) {
            $this->stdout(
              "  [SKIP] DiagnosticReport sudah ada " .
                "untuk RM {$row['rm']}\n"
            );
          } else {
            try {
              $this->diagnosticReport->createRadio(
                servicerequestIdIhs: $srIdIhs,
                value: $impressionText,
                noradio: $noradio
              );

              $this->stdout(
                "  [OK] DiagnosticReport berhasil dibuat " .
                  "untuk RM {$row['rm']}\n"
              );
            } catch (Exception $e) {
              $this->stdout(
                "  [!] DiagnosticReport error: " .
                  $e->getMessage() . "\n"
              );
            }
          }
        }
      }
    } catch (Exception $e) {
      $this->stdout(
        "[CRITICAL] Error: {$e->getMessage()}\n"
      );
    }
  }

  private function stdout(string $message): void
  {
    echo $message;
  }
}
