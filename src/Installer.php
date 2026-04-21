<?php

namespace ItYpr\SshtApiGwClient;

class Installer
{
  public static function copyModule()
  {
    $source = __DIR__ . '/../stubs/SshtApiGwClient';
    $destination = dirname(__DIR__, 4) . '/common/services/SshtApiGwClient';

    if (!is_dir($destination)) {
      mkdir($destination, 0755, true);
    }

    $files = scandir($source);
    foreach ($files as $file) {
      if (in_array($file, ['.', '..'])) continue;
      copy("$source/$file", "$destination/$file");
    }

    echo "✓ SshtApiGwClient module copied to ./common/services/SshtApiGwClient\n";
  }
}
