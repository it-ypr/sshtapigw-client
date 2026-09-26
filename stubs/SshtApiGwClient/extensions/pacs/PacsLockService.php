<?php

namespace common\services\SshtApiGwClient\extensions\pacs;

use Yii;

class PacsLockService
{
  private const PREFIX = 'ssht-pacs-noradio:';

  public function acquire(
    string $noradio,
    int $timeoutSeconds = 0
  ): bool {
    $lockName = self::PREFIX . $noradio;

    $result = Yii::$app->db
      ->createCommand(
        'SELECT GET_LOCK(:lock_name, :timeout)'
      )
      ->bindValue(':lock_name', $lockName)
      ->bindValue(':timeout', $timeoutSeconds)
      ->queryScalar();

    return (int) $result === 1;
  }

  public function release(string $noradio): void
  {
    $lockName = self::PREFIX . $noradio;

    Yii::$app->db
      ->createCommand(
        'SELECT RELEASE_LOCK(:lock_name)'
      )
      ->bindValue(':lock_name', $lockName)
      ->queryScalar();
  }

  public function run(
    string $noradio,
    callable $callback,
    int $timeoutSeconds = 0
  ): mixed {
    if (!$this->acquire($noradio, $timeoutSeconds)) {
      return null;
    }

    try {
      return $callback();
    } finally {
      $this->release($noradio);
    }
  }
}
