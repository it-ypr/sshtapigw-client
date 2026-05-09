<?php

namespace ItYpr\SshtApiGwClient;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

class Installer
{
  public static function copyModule()
  {
    $source = realpath(__DIR__ . '/../stubs/SshtApiGwClient');
    $destination = dirname(__DIR__, 4) . '/common/services/SshtApiGwClient';

    if (!$source || !is_dir($source)) {
      echo "Error: Source directory not found at $source\n";
      return;
    }

    if (!is_dir($destination)) {
      mkdir($destination, 0755, true);
    }

    $directory = new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS);
    $iterator = new RecursiveIteratorIterator($directory, RecursiveIteratorIterator::SELF_FIRST);

    foreach ($iterator as $item) {
      $targetPath = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();

      if ($item->isDir()) {
        if (!is_dir($targetPath)) {
          mkdir($targetPath, 0755, true);
        }
      } else {
        copy($item->getRealPath(), $targetPath);
      }
    }

    echo "✓ SshtApiGwClient module and sub-directories copied to: \n  $destination\n";
  }
}
