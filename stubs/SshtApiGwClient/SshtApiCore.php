<?php

namespace common\services\SshtApiGwClient;

use Exception;
use Yii;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use yii\base\Component;
use yii\db\Query;

class SshtApiCore extends Component implements SshtApiRepository
{
  private static $instance = null;
  private $client;
  private $accessToken;
  private $expiresAt; // Tambahan property untuk tracking expiry
  private $config;
  private $serviceName = 'sshtapigw';
  private $table = 'sshtapigw_token';

  public function __construct()
  {
    $this->config = Yii::$app->params['SSHTApiConfig'] ?? [];

    if (empty($this->config['baseurl'])) {
      throw new \Exception("Konfigurasi base URL tidak ditemukan.");
    }

    $clientConfig = [
      'base_uri' => $this->config['baseurl'],
      'timeout'  => 15.0,
    ];

    if (!empty($this->config['client_cert']) && !empty($this->config['client_key'])) {
      $clientConfig['cert'] = $this->config['client_cert'];
      $clientConfig['ssl_key'] = $this->config['client_key'];
    }

    if (isset($this->config['verify_ssl'])) {
      $clientConfig['verify'] = $this->config['verify_ssl'];
    }

    $this->client = new Client($clientConfig);

    $this->loadTokenFromDb();
  }

  public static function getInstance()
  {
    if (self::$instance === null) {
      self::$instance = new self();
    }
    return self::$instance;
  }

  private function loadTokenFromDb()
  {
    if (!Yii::$app->has('sshtAPIdb')) {
      return;
    }

    $row = (new Query())
      ->from($this->table)
      ->where(['service_name' => $this->serviceName])
      ->one(Yii::$app->sshtAPIdb);

    if ($row) {
      $this->accessToken = $row['access_token'];
      $this->expiresAt = $row['expires_at'];
    }
  }

  /**
   * Update saveTokenToDb untuk menangkap expiry time
   */
  private function saveTokenToDb($token, $expiresAt)
  {
    $db = Yii::$app->sshtAPIdb;

    $exists = (new Query())
      ->from($this->table)
      ->where(['service_name' => $this->serviceName])
      ->exists($db);

    $payload = [
      'access_token' => $token,
      'expires_at'   => $expiresAt, // Masuk ke kolom expires_at
      'updated_at'   => time(),
    ];

    if ($exists) {
      $db->createCommand()->update($this->table, $payload, ['service_name' => $this->serviceName])->execute();
    } else {
      $payload['service_name'] = $this->serviceName;
      $db->createCommand()->insert($this->table, $payload)->execute();
    }

    $this->accessToken = $token;
    $this->expiresAt = $expiresAt;
  }

  public function login()
  {
    try {
      Yii::info("Attempting login to API with TLS creds...", __METHOD__);

      $response = $this->client->request('POST', '/api/v1/auth/login', [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json'
        ],
        'json' => [
          'email' => $this->config['email'] ?? '',
          'password' => $this->config['password'] ?? '',
        ]
      ]);

      $data = json_decode($response->getBody(), true);

      // Sesuai response baru: access_token dan exp_act
      $token = $data['access_token'] ?? null;
      $expiry = $data['exp_act'] ?? null;

      if (!$token) {
        throw new \Exception("Token tidak ditemukan dalam response API.");
      }

      $this->saveTokenToDb($token, $expiry);
      return $token;
    } catch (RequestException $e) {
      $msg = $e->getMessage();
      if (strpos($msg, 'cURL error 58') !== false) {
        $msg = "TLS creds Error: Masalah pada sertifikat atau key (cURL 58).";
      }
      Yii::error("Login API gagal: " . $msg, __METHOD__);
      throw new \Exception($msg);
    }
  }

  public function request($module, $options = [])
  {
    /**
     * Proactive Expiry Check: 
     * Jika token kosong atau sudah lewat waktu expires_at, langsung login ulang.
     */
    if (!$this->accessToken || ($this->expiresAt && time() >= $this->expiresAt)) {
      Yii::info("Token missing or expired by timestamp. Logging in...", __METHOD__);
      $this->login();
    }

    $options['headers']['Authorization'] = "Bearer {$this->accessToken}";
    $options['headers']['Accept'] = "application/json";

    list($method, $endpoint) = $module;

    try {
      return $this->client->request($method, $endpoint, $options);
    } catch (RequestException $e) {
      $statusCode = $e->getResponse() ? $e->getResponse()->getStatusCode() : null;

      // Jika ternyata masih dapet 401 (misal clock server gak sinkron), tetep retry login
      if ($statusCode === 401) {
        Yii::warning("Token rejected (401). Retrying login...", __METHOD__);
        $this->login();

        $options['headers']['Authorization'] = "Bearer {$this->accessToken}";
        return $this->client->request($method, $endpoint, $options);
      }

      throw $e;
    }
  }

  public function getConfig()
  {
    return $this->config;
  }

  public function module()
  {
    return (new \ReflectionClass(\common\services\SshtApiGwClient\SshtApiUrl::class))->getConstants();
  }
}
