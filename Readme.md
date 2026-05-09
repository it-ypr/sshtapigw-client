## Quickstart:

### Create Tabel api token:

```sql
CREATE TABLE `sshtapigw_token` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `service_name` VARCHAR(255) NOT NULL,
    `access_token` TEXT NOT NULL,
    `expires_at` INT(11) DEFAULT NULL,
    `updated_at` INT(11) DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `idx-api_token_storage-service_name` (`service_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Install with Composer:

### 1. Install:

```sh
composer config repositories.sshtapigw-client vcs https://github.com/it-ypr/sshtapigw-client
composer require it-ypr/sshtapigw-client:^0.3
```
**Generate ./common/services/SshtApiGwClient:**

```sh
php ./vendor/it-ypr/sshtapigw-client/generate.php
```

### 2. Copy this part to ./common/config/main-local.php

```php
<?php

return [
    //...
    'sshtConf' => [
      'class' => 'common\services\SshtApiGwClient\SshtApiCore',
    ],
    'sshtAPIdb' => [ // db ssht lokal
      'class' => 'yii\db\Connection',
      'dsn' => 'mysql:host=localhost;dbname=UNITNAME_satusehat',
      'username' => 'USERNAME_DB_NYA',
      'password' => 'PASSWORD_NYA',
      'charset' => 'utf8',
    ],
    'db_terminologi' => [ // db terminologi untuk icd10 etc..
      'class' => 'yii\db\Connection',
      'dsn' => 'mysql:host=localhost;dbname=satusehat_terminologi',
      'username' => 'USERNAME_DB_NYA',
      'password' => 'PASSWORD_NYA',
      'charset' => 'utf8',
    ],
   // ...
];
```

### 3. Copy this part to ./common/config/params.php

```php

<?php

return [
  // ...
  'SSHTApiConfig' => [
    'debug' => true, // debug mode = true/false
    'baseurl' => 'SSHTAPIGW_BASE_URL',
    'email' => 'SSHTAPIGW_EMAIL',
    'password' => 'SSHTAPIGW_PASSWORD',
    'client_cert' => __DIR__ . '/certs/client.crt',
    'client_key' => __DIR__ . '/certs/client.key',
    'verify_ssl' => false,
    'orthanc_url' => 'http://your-orthanc-url:8042',
    'orthanc_auth_user' => 'ORTHANC_AUTH_USER',
    'orthanc_auth_password' => 'ORTHANC_AUTH_PASSWORD',
    'dicom_router_name' => 'dicom-router'
  ],
  // ...
];
```

### 4. Copy this part to ./console/config/main.php

```php
<?php

...

return [
  ...
  'controllerMap' => [
    ...
    'ssht-api-client' => [
      'class' => 'common\services\SshtApiGwClient\console\SshtApiClientController',
      // 'namespace' => 'common\services\SshtApiGwClient\console',
    ],
    ...
  ],
  ...
];
```

## Manual:

### 1. Git Clone:

```sh
git clone https://github.com/it-ypr/sshtapigw-client.git
```

### 2. Copy SshtApiGwClient from ./stub to dir ./common/config/services/


### 3. Copy this part to ./common/config/main-local.php

```php
<?php

return [
    //...
    'sshtConf' => [
      'class' => 'common\services\SshtApiGwClient\SshtApiCore',
    ],
    'sshtAPIdb' => [ // db ssht lokal
      'class' => 'yii\db\Connection',
      'dsn' => 'mysql:host=localhost;dbname=UNITNAME_satusehat',
      'username' => 'USERNAME_DB_NYA',
      'password' => 'PASSWORD_NYA',
      'charset' => 'utf8',
    ],
   // ...
];
```

### 4. Copy this part to ./common/config/params.php

```php

<?php

return [
  // ...
  'SSHTApiConfig' => [
    'debug' => true, // debug mode = true/false
    'baseurl' => 'SSHTAPIGW_BASE_URL',
    'email' => 'SSHTAPIGW_EMAIL',
    'password' => 'SSHTAPIGW_PASSWORD',
    'client_cert' => __DIR__ . '/certs/client.crt',
    'client_key' => __DIR__ . '/certs/client.key',
    'verify_ssl' => false,
    'orthanc_url' => 'http://your-orthanc-url:8042',
    'orthanc_auth_user' => 'ORTHANC_AUTH_USER',
    'orthanc_auth_password' => 'ORTHANC_AUTH_PASSWORD',
    'dicom_router_name' => 'dicom-router'
  ],
  // ...
];
```

## Usage: 

### GET using query param:

```php
use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;

...
$response = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_BY_NIK, [
    'query' => [
      'id' => $idnik,
    ],
]);

// $resultJson = $response->json(); // get result format json (DEPRECATED: only work on guzzle v5)
$code = $response->getStatusCode(); // 200 - get header status
$reason = $response->getReasonPhrase(); // OK - get header message
$result = $response->getBody() // get only body response
$resultArray = json_decode((string) $result, true)
// or
$resultToArray = json_decode((string) $response->getBody(), true);
```

### POST using body param:

```php
use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;

...
$response = SshtApiBase::request(SshtApiUrl::PATIENTS_GET_DATA, [
    'json' => [
      'nik' => $idnik,
      'nama' => $nama,
      'birthdate' => $tgl,
    ],
]);

// $resultJson = $response->json(); // get result format json (DEPRECATED: only work on guzzle v5)
$code = $response->getStatusCode(); // 200 - get header status
$reason = $response->getReasonPhrase(); // OK - get header message
$result = $response->getBody() // get only body response
$resultArray = json_decode((string)$result, true);
// or
$resultToArray = json_decode((string) $response->getBody(), true);
```

### Run console command:

```sh
php yii ssht-api-client/test-hello 
```

## Source:

- [Guzzle-Response](https://docs.guzzlephp.org/en/stable/quickstart.html#using-responses) 
