<?php

namespace common\services\SshtApiGwClient\console;

use common\services\SshtApiGwClient\extensions\PacsMigrationService;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;

class PacsConsoleController extends Controller
{
  /**
   * Migrasi foto legacy dari db image ke Orthanc.
   *
   * Usage:
   * php yii pacs-console/migrate-legacy-dicom 2026-09-01
   */
  public function actionMigrateLegacyDicom(string $tanggal)
  {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tanggal)) {
      $this->stderr(
        "Format tanggal harus YYYY-MM-DD\n",
        \yii\helpers\Console::FG_RED
      );

      return ExitCode::UNSPECIFIED_ERROR;
    }

    $this->stdout(
      "=== PACS Legacy DICOM Migration ===\n"
    );

    $this->stdout(
      "Tanggal : {$tanggal}\n\n"
    );

    try {
      $service = new PacsMigrationService();

      $result = $service->migrateByDate($tanggal);

      $this->stdout("\n");
      $this->stdout("=== RESULT ===\n");
      $this->stdout(
        "Noradio : {$result['total_noradio']}\n"
      );
      $this->stdout(
        "Foto    : {$result['total_foto']}\n"
      );
      $this->stdout(
        "Success : {$result['success']}\n"
      );
      $this->stdout(
        "Failed  : {$result['failed']}\n"
      );
      $this->stdout(
        "Skipped : {$result['skipped']}\n"
      );

      return $result['failed'] > 0
        ? ExitCode::UNSPECIFIED_ERROR
        : ExitCode::OK;
    } catch (\Throwable $e) {
      $this->stderr(
        "\nMigration gagal:\n{$e->getMessage()}\n",
        \yii\helpers\Console::FG_RED
      );

      Yii::error([
        'event' => 'pacs_migration_command_failed',
        'tanggal' => $tanggal,
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
      ], 'pacs-migration');

      return ExitCode::UNSPECIFIED_ERROR;
    }
  }
}
