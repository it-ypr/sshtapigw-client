<?php

namespace common\services\SshtApiGwClient;

interface SshtApiRepository
{
  public static function getInstance();
  public function module();
  public function request($module, $options = []);
}
