<?php

namespace common\services\SshtApiGwClient\console;

use common\services\SshtApiGwClient\extensions\resources\SshtDiagnosticReportService;
use common\services\SshtApiGwClient\extensions\resources\SshtEncounterService;
use common\services\SshtApiGwClient\extensions\resources\SshtImagingStudyService;
use common\services\SshtApiGwClient\extensions\resources\SshtMedicationDispenseService;
use common\services\SshtApiGwClient\extensions\resources\SshtMedicationRequestService;
use common\services\SshtApiGwClient\extensions\resources\SshtMedicationService;
use common\services\SshtApiGwClient\extensions\resources\SshtObservationService;
use common\services\SshtApiGwClient\extensions\resources\SshtProcedureService;
use common\services\SshtApiGwClient\extensions\resources\SshtRadiologyResultService;
use common\services\SshtApiGwClient\extensions\resources\SshtServiceRequestService;
use common\services\SshtApiGwClient\extensions\resources\SshtSpecimenService;
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

class SshtApiClientController extends Controller
{
  /**
   * Run: php yii ssht-api-client/send-task-ralan 2026-05-01
   * Exp: 30 19 * * * php yii ssht-api-client/send-task-ralan "$(date -d 'yesterday' +%Y-%m-%d)"
   * Cron:
   * 0 13 * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/send-task-ralan "$(date -d yesterday +\%F)"'
   * Cron dengan Log:
   * 0 13 * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/send-task-ralan "$(date -d yesterday +\%F)"' >> /home/{direktori-log-terserah}/ssht-api-client_send-task-ralan.log 2>&1
   * cron send on (h-1) now()-1day
   */
  public function actionSendTaskRalan(string $tgl_param)
  {
    // encounter & diagnosa
    $this->actionSendEncounterRalan($tgl_param);
    // observasi vital
    $this->actionSendObservationRalan($tgl_param);
    // general procedure
    $this->actionSendProcedureGeneralRalan($tgl_param);
    // serviceRequest Radiologi
    $this->actionSendServiceRequestRadioRalan($tgl_param);
    // imagingStudy
    $this->actionSendImagingStudyRalan($tgl_param);
    // observation & diagnosticReport Radiologi
    $this->actionSendObservationDanDiagnosticReportRadioRalan($tgl_param);
    // medicationRequest & medicationDispense (inprogress)
    $this->actionGenerateMedicationRequestRalan($tgl_param);
    // send medicationRequest manual (CLI/command prompt):
    // 15 * * * * php yii ssht-api-client/task-send-medication-request-ralan
    // send medicationRequest via cron:
    // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan 2>&1'
    // send medicationRequest via cron with print logs:
    // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan >> /home/psidev/apps/logs/medication-request-$(date +\%Y-\%m-\%d).log 2>&1'
    // send medicationDispense manual (CLI/command prompt):
    // 15 * * * * php yii ssht-api-client/task-send-medication-dispense-ralan
    // send medicationDispense on cron:
    // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan 2>&1'
    // send medicationDispense on cron with print logs:
    // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-dispense-ralan >> /home/psidev/apps/logs/medication-dispense-$(date +\%Y-\%m-\%d).log 2>&1'
    // Lab - ServiceRequest & Speciment
    $this->actionSendServiceRequestAndSpecimentLabRalan($tgl_param);
    // Lab - Observation & DiagnosticReport (on-testing)
    // Observation Lab - saat ini baru untuk tipe panel Quantitative contoh: darah rutin
    $this->actionSendObservationLabRalan($tgl_param);
    // DiagnosticReport - alur 1 serviceRequest -> 1 DiagnosticReport menyesuaikan ssht..
    $this->actionSendDiagnosticReportLabRalan($tgl_param);
    // EncounterFinish (inprogress)
  }

  /**
   * Run: php yii ssht-api-client/send-task-ranap 2026-05-01
   */
  public function actionSendTaskRanap(string $tgl_param)
  {
    // RANAP - inprogress 
    // // encounter & diagnosa
    // $this->actionSendEncounterRanap($tgl_param);
    // // observasi vital
    // $this->actionSendObservationRanap($tgl_param);
    // // general procedure
    // $this->actionSendProcedureGeneralRanap($tgl_param);
    // // serviceRequest Radiologi
    // $this->actionSendServiceRequestRadio($tgl_param);
    // // imagingStudy
    // $this->actionSendImagingStudy($tgl_param);
    // // observation & diagnosticReport Radiologi
    // $this->actionSendObservationDanDiagnosticReportRadio($tgl_param);
    // medicationRequest & medicationDispense (inprogress)
    // Lab - ServiceRequest (inprogress)
    // Lab - Speciment (inprogress)
    // Lab - Observation & DiagnosticReport (inprogress)
    // EncounterFinish (inprogress)
  }

  /**
   * Run: php yii ssht-api-client/send-task-ugd 2026-05-01
   */
  public function actionSendTaskUgd(string $tgl_param)
  {
    // // UGD - inprogress 
    // // // encounter & diagnosa
    // $this->actionSendEncounterUgd($tgl_param);
    // // // observasi vital
    // $this->actionSendObservationUgd($tgl_param);
    // // // general procedure
    // $this->actionSendProcedureGeneralUgd($tgl_param);
    // // // // serviceRequest Radiologi
    // $this->actionSendServiceRequestRadioUgd($tgl_param);
    // // // imagingStudy
    // $this->actionSendImagingStudyUgd($tgl_param);
    // // observation & diagnosticReport Radiologi
    // $this->actionSendObservationDanDiagnosticReportRadioUgd($tgl_param);
    // // medicationRequest & medicationDispense (inprogress)
    // $this->actionGenerateMedicationRequestUgd($tgl_param);
    // // send medicationRequest manual (CLI/command prompt):
    // // 15 * * * * php yii ssht-api-client/task-send-medication-request-ralan
    // // send medicationRequest via cron:
    // // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan 2>&1'
    // // send medicationRequest via cron with print logs:
    // // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan >> /home/psidev/apps/logs/medication-request-$(date +\%Y-\%m-\%d).log 2>&1'
    // // send medicationDispense manual (CLI/command prompt):
    // // 15 * * * * php yii ssht-api-client/task-send-medication-dispense-ralan
    // // send medicationDispense on cron:
    // // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-request-ralan 2>&1'
    // // send medicationDispense on cron with print logs:
    // // 15 * * * * /bin/bash -lc 'cd /var/www/{direktori-simrs} && /usr/bin/php yii ssht-api-client/task-send-medication-dispense-ralan >> /home/psidev/apps/logs/medication-dispense-$(date +\%Y-\%m-\%d).log 2>&1'
    // // Lab - ServiceRequest & Speciment
    $this->actionSendServiceRequestAndSpecimentLabUgd($tgl_param);
    // // Lab - Observation & DiagnosticReport (on-testing)
    // // Observation Lab - saat ini baru untuk tipe panel Quantitative contoh: darah rutin
    // $this->actionSendObservationLabUgd($tgl_param);
    // // // DiagnosticReport - alur 1 serviceRequest -> 1 DiagnosticReport menyesuaikan ssht..
    // $this->actionSendDiagnosticReportLabUgd($tgl_param);
    // // // EncounterFinish (inprogress)
  }

  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ralan 2026-05-01
   */
  public function actionSendEncounterRalan($tgl_param)
  {
    (new SshtEncounterService())->sendRalan($tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ugd 2026-05-01
   */
  public function actionSendEncounterUgd($tgl_param)
  {
    (new SshtEncounterService())->sendUgd($tgl_param);
  }


  /**
   * Run Cron: php yii ssht-api-client/send-encounter-ranap 2026-05-01
   */
  public function actionSendEncounterRanap($tgl_param)
  {
    echo "--- TASK SSHT START: " . date('Y-m-d H:i:s') . " ---\n";
    echo "\n--- TASK DONE ---\n";
  }


  /**
   * Run: php yii ssht-api-client/send-encounter-finish-ralan-single 2026-05-01 rm
   */
  public function actionSendEncounterFinishRalanSingle($tgl_param, $rm_param)
  {
    (new SshtEncounterService())->sendFinishRalanSingle($tgl_param, $rm_param);
  }

  /**
   * Run:
   * php yii ssht-api-client/send-encounter-finish-ralan-single-local-rank 2026-05-01 123456
   *
   * Menggunakan conditionRank dari tabel ssht_condition.
   * Tidak membaca ulang diagnosis dari SIMRS.
   */
  public function actionSendEncounterFinishRalanSingleLocalRank($tgl_param, $rm_param)
  {
    (new SshtEncounterService())->sendFinishRalanSingleLocalRank($tgl_param, $rm_param);
  }

  // /**
  //  * Run Cron: php yii ssht-api-client/send-encounter-finish-ralan 2025-12-01
  //  *
  //  * Still inprogress
  //  */
  // public function actionSendEncounterFinishRalan($tgl_param)
  // {
  //   (new SshtEncounterService())->sendFinishRalan($tgl_param);
  // }

  // public function actionSendAllergy($tgl_param) {}
  //
  // public function actionSendCompositionDiet($tgl_param) {}
  //
  // public function actionSendServiceRequestEkg($tgl_param) {}
  // public function actionSendServiceRequestEco($tgl_param) {}
  // public function actionSendServiceRequestNebulasi($tgl_param) {}
  //
  // public function actionSendProcedureDanObservationEkg($tgl_param) {}
  // public function actionSendProcedureDanObservationEco($tgl_param) {}
  // public function actionSendProcedureDanObservationNebulasi($tgl_param) {}

  /**
   * Run Cron: php yii ssht-api-client/send-observation-lab-ralan-single 2026-05-01 rm
   */
  public function actionSendObservationLabRalanSingle(string $tgl_param, string $rm)
  {
    (new SshtObservationService())->sendLabRalanSingle(tgl_param: $tgl_param, rm: $rm);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-observation-lab-ralan 2026-05-01
   */
  public function actionSendObservationLabRalan(string $tgl_param)
  {
    (new SshtObservationService())->sendLabRalan($tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-observation-lab-ugd 2026-05-01
   */
  public function actionSendObservationLabUgd(string $tgl_param)
  {
    (new SshtObservationService())->sendLabUgd($tgl_param);
  }

  /**
   * Run: php yii ssht-api-client/send-diagnostic-report-lab-ralan-single 2025-09-29 rm
   */
  public function actionSendDiagnosticReportLabRalanSingle(string $tgl_param, string $rm)
  {
    (new SshtDiagnosticReportService())->sendLabRalanSingle(tgl_param: $tgl_param, rm: $rm);
  }

  /**
   * Run: php yii ssht-api-client/send-diagnostic-report-lab-ralan 2025-09-29
   */
  public function actionSendDiagnosticReportLabRalan(string $tgl_param)
  {
    (new SshtDiagnosticReportService())->sendLabRalan($tgl_param);
  }

  /**
   * Run: php yii ssht-api-client/send-diagnostic-report-lab-ralan 2025-09-29
   */
  public function actionSendDiagnosticReportLabUgd(string $tgl_param)
  {
    (new SshtDiagnosticReportService())->sendLabUgd($tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-and-speciment-lab-ralan 2026-05-01
   */
  public function actionSendServiceRequestAndSpecimentLabRalan(string $tgl_param)
  {
    (new SshtServiceRequestService())->sendLabRalan(tgl_param: $tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-and-speciment-lab-ralan 2026-05-01
   */
  public function actionSendServiceRequestAndSpecimentLabUgd(string $tgl_param)
  {
    (new SshtServiceRequestService())->sendLabUgd(tgl_param: $tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-radio-ralan 2026-05-01
   */
  public function actionSendServiceRequestRadioRalan($tgl_param)
  {
    (new SshtServiceRequestService())->sendRadioRalan($tgl_param);
  }

  /**
   * Run Cron: php yii ssht-api-client/send-service-request-radio-ugd 2026-05-01
   */
  public function actionSendServiceRequestRadioUgd($tgl_param)
  {
    (new SshtServiceRequestService())->sendRadioUgd($tgl_param);
  }

  /**
   * Jalankan dengan: php yii ssht-api-client/send-imaging-study-ralan 2026-05-01
   */
  public function actionSendImagingStudyRalan($tgl_param)
  {
    (new SshtImagingStudyService())->sendRalan($tgl_param);
  }

  /**
   * Jalankan dengan: php yii ssht-api-client/send-imaging-study-ranap 2026-05-01
   */
  public function actionSendImagingStudyRanap($tgl_param)
  {
    // (new SshtImagingStudyService())->sendRanap($tgl_param);
  }

  /**
   * Jalankan dengan: php yii ssht-api-client/send-imaging-study-ugd 2026-05-01
   */
  public function actionSendImagingStudyUgd($tgl_param)
  {
    (new SshtImagingStudyService())->sendUgd($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-observation-dan-diagnostic-report-radio-ralan 2026-05-01
   */
  public function actionSendObservationDanDiagnosticReportRadioRalan($tgl_param)
  {
    (new SshtRadiologyResultService())->sendRalan($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-observation-dan-diagnostic-report-radio-ugd 2026-05-01
   */
  public function actionSendObservationDanDiagnosticReportRadioUgd($tgl_param)
  {
    (new SshtRadiologyResultService())->sendUgd($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-observation-ralan 2026-05-01
   */
  // public function actionSendObservationRalan($tanggal, $rm)
  public function actionSendObservationRalan($tgl_param)
  {
    (new SshtObservationService())->sendRalan($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-observation-ugd 2026-05-01
   */
  // public function actionSendObservationRalan($tanggal, $rm)
  public function actionSendObservationUgd($tgl_param)
  {
    (new SshtObservationService())->sendUgd($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-procedure-general-ralan 2026-05-01
   */
  public function actionSendProcedureGeneralRalan($tgl_param)
  {
    (new SshtProcedureService())->sendGeneralRalan($tgl_param);
  }

  /**
   * php yii ssht-api-client/send-procedure-general-ugd 2026-05-01
   */
  public function actionSendProcedureGeneralUgd($tgl_param)
  {
    (new SshtProcedureService())->sendGeneralUgd($tgl_param);
  }

  /**
   * php yii ssht-api-client/sync-refobatbriging-medication-single <kode_lokal_id_obat>
   */
  public function actionSyncRefobatbrigingMedicationSingle($local_id)
  {
    try {

      $service = new SshtMedicationService();

      $result = $service->syncByLocalId($local_id);

      if ($result['already_mapped'] ?? false) {
        $this->stdout("[-] sudah dimaping..\n");
        return;
      }

      if ($result['duplicate'] ?? false) {
        $this->stdout("[-] medication duplicate..\n");
        return;
      }

      $this->stdout("[+] Sukses sync\n");
      $this->stdout(
        "[+] medication_idIHS: {$result['medication_idIHS']}\n"
      );
      $this->stdout(
        "[+] local_id: {$local_id}\n"
      );
    } catch (Exception $e) {

      $this->stdout(
        "ERROR: {$e->getMessage()}\n"
      );
    }
  }

  /**
   * php yii ssht-api-client/generate-medication-request-ralan-single 2026-05-01 $rm
   */
  public function actionGenerateMedicationRequestRalanSingle(string $tgl_param, string $rm)
  {
    (new SshtMedicationRequestService())->generateRalanSingle(tgl_param: $tgl_param, rm: $rm);
  }

  /**
   * php yii ssht-api-client/generate-medication-request-ralan 2026-05-01
   * identifier_noresep_index (unique)
   */
  public function actionGenerateMedicationRequestRalan(string $tgl_param)
  {
    (new SshtMedicationRequestService())->generateRalan(tgl_param: $tgl_param);
  }
  // end class console

  /**
   * php yii ssht-api-client/generate-medication-request-ralan 2026-05-01
   * identifier_noresep_index (unique)
   */
  public function actionGenerateMedicationRequestUgd(string $tgl_param)
  {
    (new SshtMedicationRequestService())->generateUgd(tgl_param: $tgl_param);
  }

  /**
   * php yii ssht-api-client/task-send-medication-request-ralan (--send_status=P)
   * 15 * * * * cron medication ralan
   */
  public function actionTaskSendMedicationRequestRalan()
  {
    (new SshtMedicationRequestService())->sendTaskRalan();
  }

  /**
   * php yii ssht-api-client/generate-medication-dispense-ralan-single 2026-05-01 $rm
   */
  public function actionGenerateMedicationDispenseRalanSingle(string $tgl_param, string $rm)
  {
    (new SshtMedicationDispenseService())->generateRalanSingle(tgl_param: $tgl_param, rm: $rm);
  }

  /**
   * php yii ssht-api-client/task-send-medication-dispense-ralan (--send_status=P)
   * 15 * * * * cron medication ralan
   */
  public function actionTaskSendMedicationDispenseRalan()
  {
    (new SshtMedicationDispenseService())->sendTaskRalan();
  }
}
