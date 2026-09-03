<?php

namespace common\services\SshtApiGwClient\extensions;

use Exception;
use Yii;
use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiDebugger;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\util\SshtApiUtil;


class SshtMedicationService
{
  public function syncByLocalId($local_id)
  {
    $getLocalObt = SshtApiQueryMapping::getRefObatByLocalId($local_id);

    if (!$getLocalObt) {
      throw new Exception("Tidak ada obat dengan local_id: {$local_id}");
    }

    if ($getLocalObt['medication_idIHS']) {
      return [
        'success' => true,
        'already_mapped' => true,
        'medication_idIHS' => $getLocalObt['medication_idIHS'],
      ];
    }

    $payloadMedication = [
      'local_id' => (string) $getLocalObt['id_local'],
      'kfa_code' => $getLocalObt['kfa_code'],
      'kfa_display' => $getLocalObt['kfa_display'],
      'kfa_bza' => json_decode($getLocalObt['kfa_bza'], true),
      'kfa_form' => json_decode($getLocalObt['kfa_form'], true),
      'kfa_route' => json_decode($getLocalObt['kfa_route'], true),
    ];

    $response = SshtApiBase::request(
      SshtApiUrl::MEDICATION_CREATE,
      [
        'json' => $payloadMedication,
      ]
    );

    $resReq = json_decode(
      (string) $response->getBody(),
      true
    );

    if (
      $response->getStatusCode() == 400 &&
      ($resReq['errors']['code'] ?? null) === 'duplicate'
    ) {
      return [
        'success' => false,
        'duplicate' => true,
        'response' => $resReq,
      ];
    }

    $medication_idIHS =
      $resReq['data']['medication_idIHS'] ?? null;

    if (!$medication_idIHS) {
      throw new Exception(
        'Medication ID IHS tidak ditemukan dari response API'
      );
    }

    Yii::$app->db->createCommand()->update(
      'ref_obat_briging',
      [
        'medication_idIHS' => $medication_idIHS,
        'updated_at' => date('Y-m-d H:i:s'),
      ],
      [
        'id_local' => $local_id,
        'kfa_code' => $getLocalObt['kfa_code'],
      ]
    )->execute();

    return [
      'success' => true,
      'already_mapped' => false,
      'medication_idIHS' => $medication_idIHS,
      'response' => $resReq,
    ];
  }
}
