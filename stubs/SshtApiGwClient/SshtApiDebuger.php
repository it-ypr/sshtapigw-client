<?php

namespace common\services\SshtApiGwClient;

class SshtApiDebugger
{
  public function __construct(
    protected bool $enabled = false
  ) {}

  public function allow(
    array $context = [],
    mixed $payload = null,
  ): bool {

    // debug OFF
    if (!$this->enabled) {
      return true;
    }

    $this->render($context, $payload);

    // $answer = strtolower(
    //   trim(readline('Kirim ke API? [Enter/y]=continue, s=skip, q=quit > '))
    // );

    echo "Kirim ke API? [Enter/y]=continue, s=skip, q=quit > ";
    $answer = strtolower(trim(fgets(STDIN)));

    if ($answer === 'q') {
      exit;
    }

    return $answer !== 's';
  }

  protected function render(array $context, mixed $payload): void
  {
    echo PHP_EOL;

    echo "=== DEBUG ===" . PHP_EOL;

    print_r($context);

    echo PHP_EOL;

    print_r($payload);

    echo PHP_EOL;
  }
}
