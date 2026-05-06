<?php

namespace common\services\SshtApiGwClient;

use Exception;
use Yii;

class SshtApiBase
{
  /**
   * Sends an API request using SshtApiCore singleton instance.
   *
   * @param array $apiUrlConstant The API URL constant containing HTTP method and endpoint URL.
   *                              Example: SshtApiUrl::DASHBOARD_LOOK_INDEX
   *                              those would contain array: ['GET', 'api/v1/ssht/misc/dashboard-look/index']
   *                              Each wrapper on SshtApiUrl::class would contain detail of url methods and opts.
   * @param array $options Additional request options such as query parameters or JSON body.
   *                       Example for GET request: ['query' => ['page' => 1]]
   *                       Example for POST request: ['json' => ['key' => 'value']]
   * @return mixed The API response as an mixed return values.
   *
   * @throws \Exception If the API request fails or encounters an error.
   */
  public static function request(array $apiUrlConstant, array $options)
  {
    return SshtApiCore::getInstance()->request($apiUrlConstant, $options);
  }

  public static function getConfig()
  {
    return SshtApiCore::getInstance()->getConfig();
  }
}
