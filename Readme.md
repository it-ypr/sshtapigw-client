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
composer require it-ypr/sshtapigw-client
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
   // ...
];
```

### 3. Copy this part to ./common/config/params.php

```php

<?php

return [
  // ...
  'SSHTApiConfig' => [
    'baseurl' => 'SSHTAPIGW_BASE_URL',
    'email' => 'SSHTAPIGW_EMAIL',
    'password' => 'SSHTAPIGW_PASSWORD',
    'client_cert' => '/var/www/certs/client.crt',
    'client_key' => '/var/www/certs/client.key',
    'verify_ssl' => false,
  ],
  // ...
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
    'baseurl' => 'SSHTAPIGW_BASE_URL',
    'email' => 'SSHTAPIGW_EMAIL',
    'password' => 'SSHTAPIGW_PASSWORD',
    'client_cert' => '/var/www/certs/client.crt',
    'client_key' => '/var/www/certs/client.key',
    'verify_ssl' => false,
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
```
