<?php

namespace common\services\SshtApiGwClient\extensions;

use common\components\Config;
// use common\services\SshtApiGwClient\SshtApiBase;
use GuzzleHttp\Client;
use Yii;

class OrthancService
{
  private Client $client;

  private string $baseUrl;

  public function __construct()
  {
    // $config = SshtApiBase::getConfig();
    $this->baseUrl = rtrim(
      Config::get("services.orthanc.url"),
      // $config["orthanc_url"],
      // Yii::$app->params['orthanc_url'],
      '/'
    );

    $this->client = new Client([
      'base_uri' => $this->baseUrl,
      'auth' => [Config::get("services.orthanc.auth_user"), Config::get("services.orthanc.auth_password")],
      // 'auth' => [
      //   Yii::$app->params['orthanc_auth_user'],
      //   Yii::$app->params['orthanc_auth_password'],
      // ],
      'timeout' => 30,
      'connect_timeout' => 10,
    ]);
  }

  /**
   * Create DICOM instance from JPEG binary.
   *
   * @param string $imageBinary
   * @param array $tags
   * @param string|null $parentStudyId
   *
   * @return array
   */
  public function createDicom(
    string $imageBinary,
    array $tags = [],
    ?string $parentStudyId = null
  ): array {
    $payload = [
      'Content' => 'data:image/jpeg;base64,' . base64_encode($imageBinary),
      'Tags' => $tags,
    ];

    if ($parentStudyId !== null) {
      $payload['Parent'] = $parentStudyId;
    }

    $response = $this->client->post('/tools/create-dicom', [
      'json' => $payload,
    ]);

    return $this->decodeResponse($response->getBody()->getContents());
  }

  /**
   * Get Orthanc instance.
   */
  public function getInstance(string $instanceId): array
  {
    $response = $this->client->get(
      '/instances/' . rawurlencode($instanceId)
    );

    return $this->decodeResponse($response->getBody()->getContents());
  }

  /**
   * Get Orthanc study.
   */
  public function getStudy(string $studyId): array
  {
    $response = $this->client->get(
      '/studies/' . rawurlencode($studyId)
    );

    return $this->decodeResponse($response->getBody()->getContents());
  }

  /**
   * Orthanc /tools/find.
   *
   * Example:
   *
   * [
   *   'Level' => 'Study',
   *   'Query' => [
   *      'AccessionNumber' => 'RAD001'
   *   ]
   * ]
   */
  public function find(array $query): array
  {
    $response = $this->client->post('/tools/find', [
      'json' => $query,
    ]);

    return $this->decodeResponse($response->getBody()->getContents());
  }

  /**
   * Find Study by AccessionNumber.
   *
   * Returns Orthanc Study ID or null.
   */
  public function findStudyByAccessionNumber(
    string $accessionNumber
  ): ?string {
    $result = $this->find([
      'Level' => 'Study',
      'Query' => [
        'AccessionNumber' => $accessionNumber,
      ],
    ]);

    if (empty($result)) {
      return null;
    }

    return $result[0] ?? null;
  }

  /**
   * Decode JSON response.
   */
  private function decodeResponse(string $body): array
  {
    return json_decode(
      $body,
      true,
      512,
      JSON_THROW_ON_ERROR
    );
  }

  public function getSeries(string $seriesId): array
  {
    $response = $this->client->get(
      '/series/' . rawurlencode($seriesId)
    );

    return $this->decodeResponse(
      $response->getBody()->getContents()
    );
  }
}
