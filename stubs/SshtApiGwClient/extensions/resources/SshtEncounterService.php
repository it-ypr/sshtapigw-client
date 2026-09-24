<?php

namespace common\services\SshtApiGwClient\extensions\resources;

use Exception;
use Yii;
use yii\db\Query;

use common\services\SshtApiGwClient\SshtApiBase;
use common\services\SshtApiGwClient\SshtApiUrl;
use common\services\SshtApiGwClient\SshtApiDebugger;

use common\services\SshtApiGwClient\mapping\SshtApiQueryMapping;
use common\services\SshtApiGwClient\util\SshtApiUtil;

class SshtEncounterService
{
  private $dbLocal;
  private $dbSimrs;

  private SshtApiDebugger $debugger;
  private SshtConditionService $conditionService;

  public function __construct()
  {
    $this->dbLocal = Yii::$app->sshtAPIdb;
    $this->dbSimrs = Yii::$app->db;

    $config = SshtApiBase::getConfig();

    $this->debugger = new SshtApiDebugger(
      enabled: $config['debug']
    );

    $this->conditionService = new SshtConditionService();
  }

  /*
   * Encounter - sendRalan($tgl_param)
   */
  public function sendRalan(string $tgl_param): void
  {
    // nanti pindahkan isi actionSendEncounterRalan()
    echo "--- TASK SSHT START Encounter & Diagnosa (Ralan): [{$tgl_param}] ---\n";

    $dataEncounter = SshtApiQueryMapping::queryEncounterRalanSimrs(
      $tgl_param
    );

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal {$tgl_param}\n";
      return;
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";

    foreach ($dataEncounter as $row) {
      try {
        echo "\nProcessing RM: {$row['rm']}...";

        /*
         * 1. Get Patient IHS
         */
        $ktp = preg_replace('/\D/', '', $row['ktp']);

        $resPatient = SshtApiBase::request(
          SshtApiUrl::PATIENTS_GET_BY_NIK,
          [
            'query' => [
              'id' => $ktp
            ]
          ]
        );

        $resPatientData = json_decode(
          (string) $resPatient->getBody(),
          true
        );

        sleep(1);

        $pasienIhs = $resPatientData['data']['idIHS'] ?? null;

        if (!$pasienIhs) {
          echo " SKIPPED (IHS Pasien tidak ditemukan)";
          continue;
        }

        /*
         * 2. Get Location
         */
        $resLocation = SshtApiBase::request(
          SshtApiUrl::LOCATION_GET_BY_IHS,
          [
            'query' => [
              'id' => $row['lokasi_ihs']
            ]
          ]
        );

        $locationData = json_decode(
          (string) $resLocation->getBody(),
          true
        );

        $locationNama = $locationData['data']['nama']
          ?? 'Poliklinik';

        /*
         * 3. Generate Encounter Times
         */
        $times = $this->generateEncounterTimes(
          $row['a_end']
        );

        /*
         * 4. Build Payload
         */
        $payloadEncounter = [
          'pasien_idIHS' => $pasienIhs,
          'pasien_nama' => $row['pasien_nama'],
          'pasien_rm' => $row['rm'],

          'practitioner_idIHS' => $row['dokter_ihs'],
          'practitioner_nama' => $row['dokter_nama'],

          'location_idIHS' => $row['lokasi_ihs'],
          'location_nama' => $locationNama,
          'location_poli' => $row['poli'],

          'arrived_at' => $times['arrived_at'],
          'inprogress_at' => $times['inprogress_at'],

          'class' => 'ralan',
        ];

        /*
         * 5. Duplicate Guard
         *
         * Rule:
         * 1 patient + 1 doctor + 1 location + 1 hour
         * = 1 Encounter
         */
        $hourStart = date(
          'Y-m-d H:00:00',
          strtotime($times['arrived_at'])
        );

        $hourEnd = date(
          'Y-m-d H:00:00',
          strtotime(
            $times['arrived_at'] . ' +1 hour'
          )
        );

        $duplicateEncounter = $this->dbLocal
          ->createCommand("
              SELECT idIHS
              FROM ssht_encounter
              WHERE subject_idIHS = :subject_idIHS
                AND practition_idIHS = :practitioner_idIHS
                AND location_idIHS = :location_idIHS
                AND arrived_start >= :hour_start
                AND arrived_start < :hour_end
                AND class = 'AMB'
              LIMIT 1
          ")
          ->bindValues([
            ':subject_idIHS' => $pasienIhs,
            ':practitioner_idIHS' => $row['dokter_ihs'],
            ':location_idIHS' => $row['lokasi_ihs'],
            ':hour_start' => $hourStart,
            ':hour_end' => $hourEnd,
          ])
          ->queryScalar();

        if ($duplicateEncounter) {
          echo " SKIPPED (DUPLICATE ENCOUNTER RALAN)";
          echo " [IHS: {$duplicateEncounter}]";
          echo " [{$hourStart}]\n";

          continue;
        }

        /*
         * 6. Debug / Confirmation
         */
        if (
          !$this->debugger->allow(
            context: SshtApiUtil::genDebugContext(
              SshtApiUrl::ENCOUNTER_CREATE
            ),
            payload: $payloadEncounter,
          )
        ) {
          continue;
        }

        /*
         * 7. Create Encounter
         */
        $resEncReq = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_CREATE,
          [
            'json' => $payloadEncounter
          ]
        );

        $resEnc = json_decode(
          (string) $resEncReq->getBody(),
          true
        );

        $encounterIhsId =
          $resEnc['data']['idIHS'] ?? null;

        sleep(1);

        if (!$encounterIhsId) {
          echo " FAILED ENCOUNTER";
          sleep(2);
          continue;
        }

        echo " SUCCESS ENCOUNTER: {$encounterIhsId}\n";

        /*
         * 8. Save Encounter Local
         */
        $encData = $resEnc['data'];

        $this->dbLocal
          ->createCommand()
          ->insert('ssht_encounter', [
            'idIHS' => $encData['idIHS'],
            'subject_rm' => $encData['subject_rm'],
            'subject_idIHS' => $encData['subject_idIHS'],
            'subject_nama' => $encData['subject_nama'],

            'practition_idIHS' =>
            $encData['practition_idIHS'],

            'practition_lokalid' =>
            $row['dokter'],

            'practition_nama' =>
            $encData['practition_nama'],

            'location_idIHS' =>
            $encData['location_idIHS'],

            'location_nama' =>
            $encData['location_nama'],

            'organization_idIHS' =>
            $encData['organization_idIHS'],

            'arrived_start' =>
            $encData['arrived_at'],

            'arrived_end' =>
            $encData['arrived_end'],

            'inprogress_start' =>
            $encData['inprogress_start'],

            'inprogress_end' =>
            $times['inprogress_end'],

            'created_at' =>
            date('Y-m-d H:i:s'),

            'updated_at' =>
            date('Y-m-d H:i:s'),

            'class' =>
            $encData['class'],
          ])
          ->execute();

        /*
         * 9. TODO:
         * Send Condition
         *
         * SshtConditionService.
         */
        $this->conditionService->sendForEncounter(
          encounterId: $encounterIhsId,
          patientId: $pasienIhs,
          patientName: $row['pasien_nama'],
          icdCodes: $row['icd_codes'],
          inprogressStart: $encData['inprogress_start'],
          inprogressEnd: $times['inprogress_end'],
          rm: $row['rm'],
          dokter: $row['dokter'],
        );
      } catch (Exception $e) {
        echo " ERROR: {$e->getMessage()}\n";
        sleep(5);
      }

      /*
       * Gateway rate limit
       */
      sleep(2);
    }

    echo "\n--- TASK DONE: {$tgl_param} ---\n";
  }

  /*
   * Encounter - sendUgd($tgl_param)
   */
  public function sendUgd(string $tgl_param): void
  {
    echo "--- TASK SSHT START Encounter & Diagnosa (UGD): [{$tgl_param}] ---\n";

    $dataEncounter = SshtApiQueryMapping::queryEncounterUgdSimrs($tgl_param);

    if (empty($dataEncounter)) {
      echo "Data tidak ditemukan untuk tanggal {$tgl_param}\n";
    }

    echo "Ditemukan " . count($dataEncounter) . " data.\n";

    foreach ($dataEncounter as $row) {
      try {
        echo "\nProcessing RM: {$row['rm']}...";

        // Patient
        $ktp = preg_replace('/\D/', '', $row['ktp']);

        $resPatient = SshtApiBase::request(
          SshtApiUrl::PATIENTS_GET_BY_NIK,
          ['query' => ['id' => $ktp]]
        );

        $resPatientReq = json_decode(
          (string) $resPatient->getBody(),
          true
        );

        sleep(1);

        $pasienIhs = $resPatientReq['data']['idIHS'] ?? null;

        if (!$pasienIhs) {
          echo " SKIPPED (IHS Pasien tidak ditemukan)";
          continue;
        }

        // Location
        $resLocation = SshtApiBase::request(
          SshtApiUrl::LOCATION_GET_BY_IHS,
          ['query' => ['id' => $row['lokasi_ihs']]]
        );

        $locationNamaReq = json_decode(
          (string) $resLocation->getBody(),
          true
        );

        $locationNama = $locationNamaReq['data']['nama'] ?? 'Poliklinik';

        // Generate UGD times
        $times = $this->generateEncounterTimesUgd($row['a_end']);

        // Encounter payload
        $payloadEncounter = [
          'pasien_idIHS' => $pasienIhs,
          'pasien_nama' => $row['pasien_nama'],
          'pasien_rm' => $row['rm'],
          'practitioner_idIHS' => $row['dokter_ihs'],
          'practitioner_nama' => $row['dokter_nama'],
          'location_idIHS' => $row['lokasi_ihs'],
          'location_nama' => $locationNama,
          'location_poli' => $row['poli'],
          'arrived_at' => $times['arrived_at'],
          'inprogress_at' => $times['inprogress_at'],
          'class' => 'ugd',
        ];

        // Duplicate: 1 patient + 1 doctor + 1 location + 1 hour
        $hourStart = date(
          'Y-m-d H:00:00',
          strtotime($times['arrived_at'])
        );

        $hourEnd = date(
          'Y-m-d H:00:00',
          strtotime($times['arrived_at'] . ' +1 hour')
        );

        $duplicateEncounter = $this->dbLocal
          ->createCommand("
                    SELECT idIHS
                    FROM ssht_encounter
                    WHERE subject_idIHS = :subject_idIHS
                      AND practition_idIHS = :practitioner_idIHS
                      AND location_idIHS = :location_idIHS
                      AND arrived_start >= :hour_start
                      AND arrived_start < :hour_end
                      AND class = 'EMER'
                    LIMIT 1
                ")
          ->bindValues([
            ':subject_idIHS' => $pasienIhs,
            ':practitioner_idIHS' => $row['dokter_ihs'],
            ':location_idIHS' => $row['lokasi_ihs'],
            ':hour_start' => $hourStart,
            ':hour_end' => $hourEnd,
          ])
          ->queryScalar();

        if ($duplicateEncounter) {
          echo " SKIPPED (DUPLICATE ENCOUNTER UGD)";
          echo " [IHS: {$duplicateEncounter}]";
          echo " [{$hourStart}]\n";

          continue;
        }

        // Debug
        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::ENCOUNTER_CREATE
          ),
          payload: $payloadEncounter,
        )) {
          continue;
        }

        // Create Encounter
        $resEncReq = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_CREATE,
          ['json' => $payloadEncounter]
        );

        $resEnc = json_decode(
          (string) $resEncReq->getBody(),
          true
        );

        $encounterIhsId = $resEnc['data']['idIHS'] ?? null;

        sleep(1);

        if (!$encounterIhsId) {
          echo " FAILED ENCOUNTER";
          sleep(2);
          continue;
        }

        echo " SUCCESS ENCOUNTER: {$encounterIhsId}\n";

        $encData = $resEnc['data'];

        // Save local Encounter
        $this->dbLocal
          ->createCommand()
          ->insert('ssht_encounter', [
            'idIHS' => $encData['idIHS'],
            'subject_rm' => $encData['subject_rm'],
            'subject_idIHS' => $encData['subject_idIHS'],
            'subject_nama' => $encData['subject_nama'],
            'practition_idIHS' => $encData['practition_idIHS'],
            'practition_lokalid' => $row['dokter'],
            'practition_nama' => $encData['practition_nama'],
            'location_idIHS' => $encData['location_idIHS'],
            'location_nama' => $encData['location_nama'],
            'organization_idIHS' => $encData['organization_idIHS'],
            'arrived_start' => $encData['arrived_at'],
            'arrived_end' => $encData['arrived_end'],
            'triaged_start' => $encData['triaged_at'] ?? null,
            'triaged_end' => $encData['triaged_end'] ?? null,
            'inprogress_start' => $encData['inprogress_start'],
            'inprogress_end' => $times['inprogress_end'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
            'class' => $encData['class'],
          ])
          ->execute();

        // Send Condition melalui ConditionService
        $this->conditionService->sendForEncounter(
          encounterId: $encounterIhsId,
          patientId: $pasienIhs,
          patientName: $row['pasien_nama'],
          icdCodes: $row['icd_codes'],
          inprogressStart: $encData['inprogress_start'],
          inprogressEnd: $times['inprogress_end'],
          rm: $row['rm'],
          dokter: $row['dokter'],
        );
      } catch (Exception $e) {
        echo " ERROR: " . $e->getMessage() . "\n";
        sleep(5);
      }

      // rate limit gateway
      sleep(2);
    }

    echo "\n--- TASK DONE: {$tgl_param} ---\n";
  }

  public function sendRanap(string $tgl_param): void
  {
    // blomm...
  }

  public function sendFinishRalan(string $tgl_param): void
  {
    $classEnc = 'AMB';

    echo "--- TASK SSHT EncounterFinish START: {$tgl_param} ---\n";

    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_lokalid',
        'practition_idIHS',
        'practition_nama',
        'location_idIHS',
        'location_nama',
        'arrived_start',
        'arrived_end',
        'inprogress_start',
        'inprogress_end',
        'finish_start',
        'finish_end',
        'class',
      ])
      ->from('ssht_encounter')
      ->where([
        'like',
        'arrived_start',
        $tgl_param . '%',
        false,
      ])
      ->andWhere([
        'class' => $classEnc,
      ])
      ->all($this->dbLocal);

    foreach ($encounter as $record) {

      // =====================================================
      // GET ENCOUNTER
      // =====================================================

      if (!$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::ENCOUNTER_GET
        ),
        payload: $record['idIHS'],
      )) {
        continue;
      }

      $resEncReq = SshtApiBase::request(
        SshtApiUrl::ENCOUNTER_GET,
        [
          'query' => [
            'id' => $record['idIHS'],
          ],
        ]
      );

      $resEnc = json_decode(
        (string) $resEncReq->getBody(),
        true
      );

      $resEncData = $resEnc['data'] ?? [];
      $encounterIhsId = $resEncData['idIHS'] ?? null;

      echo "\nData Encounter Get:\n";
      print_r($resEnc);

      if (
        $resEncReq->getStatusCode() != 200 ||
        ($resEnc['status'] ?? 'false') !== 'true'
      ) {
        continue;
      }

      // =====================================================
      // GET CONDITION FROM SIMRS
      // =====================================================

      $getconditionsSimrs = (new Query())
        ->select([
          'k.Id',
          'k.rm',
          'k.icd',
          'k.dokter',
          'k.poli',
          'mpoli.' .
            SshtApiQueryMapping::MPOLI_IHS,
        ])
        ->from('mr_kunjungan k')
        ->leftJoin(
          'mpoli',
          'k.poli = mpoli.poli'
        )
        ->where([
          'k.rm' => $record['subject_rm'],
          'k.dokter' => $record['practition_lokalid'],
          'k.tanggal' => $tgl_param,
          'mpoli.' .
            SshtApiQueryMapping::MPOLI_IHS =>
          $record['location_idIHS'],
        ])
        ->orderBy('Id ASC')
        ->all($this->dbSimrs);

      // =====================================================
      // GET LOCAL CONDITION
      // =====================================================

      $getConditionLocal = (new Query())
        ->select([
          'condition_idIHS',
          'encounter_idIHS',
          'code',
          'display',
          'conditionRank',
        ])
        ->from('ssht_condition')
        ->where([
          'encounter_idIHS' => $encounterIhsId,
        ])
        ->all($this->dbLocal);

      // =====================================================
      // SIMRS ICD ORDER
      // =====================================================

      $simrsOrder = [];

      foreach ($getconditionsSimrs as $index => $row) {

        $cleanIcd = preg_replace(
          '/[^A-Za-z0-9]/',
          '',
          $row['icd']
        );

        $simrsOrder[$cleanIcd] = $index + 1;
      }

      // =====================================================
      // BUILD DIAGNOSIS
      // =====================================================

      $diagnosis = array_map(
        function ($localItem) use ($simrsOrder) {

          $cleanLocalCode = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $localItem['code']
          );

          $rank =
            $simrsOrder[$cleanLocalCode] ?? 99;

          return [
            'condition_idIHS' =>
            $localItem['condition_idIHS'],

            'code' =>
            $localItem['code'],

            'display' =>
            $localItem['display'],

            'conditionRank' =>
            (string) $rank,
          ];
        },
        $getConditionLocal
      );

      // =====================================================
      // SORT DIAGNOSIS
      // =====================================================

      usort(
        $diagnosis,
        function ($a, $b) {
          return (int) $a['conditionRank']
            <=> (int) $b['conditionRank'];
        }
      );

      // =====================================================
      // BUILD FINISH PAYLOAD
      // =====================================================

      $payloadEncounterFinish = [
        'encounter_idIHS' => $encounterIhsId,

        'patient_idIHS' =>
        $record['subject_idIHS'],

        'patient_nama' =>
        $record['subject_nama'],

        'practition_idIHS' =>
        $record['practition_idIHS'],

        'practition_nama' =>
        $record['practition_nama'],

        'location_idIHS' =>
        $record['location_idIHS'],

        'location_nama' =>
        $record['location_nama'],

        'diagnosis' => $diagnosis,

        'arrived_start' =>
        $record['arrived_start'],

        'arrived_end' =>
        $record['arrived_end'],

        'inprogress_start' =>
        $record['inprogress_start'],

        'inprogress_end' =>
        $record['inprogress_end'],

        'finish_start' =>
        $record['finish_start'],

        'finish_end' =>
        $record['finish_end'],

        'class' =>
        $resEncData['class'],
      ];

      // =====================================================
      // DEBUG
      // =====================================================

      if (!$this->debugger->allow(
        context: SshtApiUtil::genDebugContext(
          SshtApiUrl::ENCOUNTER_FINISH
        ),
        payload: $payloadEncounterFinish,
      )) {
        continue;
      }

      // =====================================================
      // SEND FINISH
      // =====================================================

      // Sengaja tetap disabled, sesuai controller lama.
      //
      // $encounterFinish = SshtApiBase::request(
      //     SshtApiUrl::ENCOUNTER_FINISH,
      //     ['json' => $payloadEncounterFinish]
      // );
    }

    echo "\n--- TASK DONE ---\n";
  }

  public function sendFinishRalanSingle(
    string $tgl_param,
    string $rm_param
  ): void {
    $classEnc = 'AMB';

    echo "--- TASK SSHT EncounterFinish START: {$tgl_param} ---\n";

    $encounter = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_lokalid',
        'practition_idIHS',
        'practition_nama',
        'location_idIHS',
        'location_nama',
        'arrived_start',
        'arrived_end',
        'inprogress_start',
        'inprogress_end',
        'finish_start',
        'finish_end',
        'class',
      ])
      ->from('ssht_encounter')
      ->where(['like', 'arrived_start', $tgl_param . '%', false])
      ->andWhere(['subject_rm' => $rm_param])
      ->andWhere(['class' => $classEnc])
      ->all($this->dbLocal);

    foreach ($encounter as $record) {
      try {

        // =====================================================
        // GET ENCOUNTER
        // =====================================================

        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::ENCOUNTER_GET
          ),
          payload: $record['idIHS'],
        )) {
          continue;
        }

        $resEncReq = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_GET,
          [
            'query' => [
              'id' => $record['idIHS'],
            ],
          ]
        );

        $resEnc = json_decode(
          (string) $resEncReq->getBody(),
          true
        );

        $resEncData = $resEnc['data'] ?? [];
        $encounterIhsId = $resEncData['idIHS'] ?? null;

        echo "\nData Encounter Get:\n";
        print_r($resEnc);

        if (
          $resEncReq->getStatusCode() != 200 ||
          ($resEnc['status'] ?? false) != 'true'
        ) {
          continue;
        }

        // =====================================================
        // GET CONDITION DARI SIMRS
        // =====================================================

        $getconditionsSimrs = (new Query())
          ->select([
            'k.Id',
            'k.rm',
            'k.icd',
            'k.dokter',
            'k.poli',
            'mpoli.' .
              SshtApiQueryMapping::MPOLI_IHS .
              ' as mpoli_ihs',
          ])
          ->from('mr_kunjungan k')
          ->leftJoin(
            'mpoli',
            'k.poli = mpoli.poli'
          )
          ->where([
            'k.rm' => $record['subject_rm'],
            'k.dokter' => $record['practition_lokalid'],
            'k.tanggal' => $tgl_param,
            'mpoli.' .
              SshtApiQueryMapping::MPOLI_IHS =>
            $record['location_idIHS'],
          ])
          ->orderBy('Id ASC')
          ->all($this->dbSimrs);

        // =====================================================
        // GET CONDITION LOCAL
        // =====================================================

        $getConditionLocal = (new Query())
          ->select([
            'condition_idIHS',
            'encounter_idIHS',
            'code',
            'display',
            'conditionRank',
          ])
          ->from('ssht_condition')
          ->where([
            'encounter_idIHS' => $encounterIhsId,
          ])
          ->all($this->dbLocal);

        // =====================================================
        // BUILD RANK BERDASARKAN URUTAN ICD SIMRS
        // =====================================================

        $simrsOrder = [];

        foreach ($getconditionsSimrs as $index => $row) {
          $cleanIcd = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $row['icd']
          );

          $simrsOrder[$cleanIcd] = $index + 1;
        }

        // =====================================================
        // MAP CONDITION LOCAL -> RANK SIMRS
        // =====================================================

        $diagnosis = array_map(function ($localItem) use ($simrsOrder) {

          $cleanLocalCode = preg_replace(
            '/[^A-Za-z0-9]/',
            '',
            $localItem['code']
          );

          // $rank = $simrsOrder[$cleanLocalCode] ?? 99;
          $rank = isset($simrsOrder[$cleanLocalCode]) ?? $simrsOrder[$cleanLocalCode];

          return [
            'condition_idIHS' => $localItem['condition_idIHS'],

            'code' => $localItem['code'],

            'display' => $localItem['display'],

            'conditionRank' => (string) $rank,
          ];
        }, $getConditionLocal);

        // =====================================================
        // SORT PRIMARY -> SECONDARY
        // =====================================================

        usort($diagnosis, function ($a, $b) {
          return (int) $a['conditionRank'] <=> (int) $b['conditionRank'];
        });

        echo "diagnosis data sort:\n";
        print_r($diagnosis);

        // =====================================================
        // UPDATE LOCAL CONDITION RANK
        // =====================================================

        foreach ($diagnosis as $diag) {

          // --- DEBUG & CONFIRMATION ---
          echo "\nSort conditionRank di table local ssht_condition? [Enter/y]=continue, s=skip, q=quit > \n";

          if (!$this->debugger->allow(
            context: SshtApiUtil::genDebugContext(SshtApiUrl::CONDITION_GET),
            payload: $diag,
          )) {
            continue;
          }

          $current = array_filter(
            $getConditionLocal,
            fn($item) => $item['condition_idIHS'] == $diag['condition_idIHS']
          );

          $current = reset($current);

          if (
            empty($current['conditionRank']) ||
            (string) $current['conditionRank'] !== (string) $diag['conditionRank']
          ) {

            $this->dbLocal
              ->createCommand()
              ->update(
                'ssht_condition',
                [
                  'conditionRank' =>
                  (string) $diag['conditionRank'],
                ],
                [
                  'condition_idIHS' =>
                  $diag['condition_idIHS'],
                ]
              )
              ->execute();
          }
        }

        // =====================================================
        // BUILD ENCOUNTER FINISH PAYLOAD
        // =====================================================

        $payloadEncounterFinish = [
          'encounter_idIHS' => $encounterIhsId,

          'rm' => $record['subject_rm'],
          'patient_idIHS' => $record['subject_idIHS'],
          'patient_nama' => $record['subject_nama'],

          'practition_idIHS' => $record['practition_idIHS'],

          'practition_nama' => $record['practition_nama'],

          'location_idIHS' => $record['location_idIHS'],

          'location_nama' => $record['location_nama'],

          'diagnosis' => $diagnosis,

          'arrived_start' => $record['arrived_start'],

          'arrived_end' => $record['arrived_end'],

          'inprogress_start' => $record['inprogress_start'],

          'inprogress_end' => $record['inprogress_end'],

          'finish_start' => $record['finish_start'] ?? $record['inprogress_end'],

          'finish_end' => $record['finish_end'] ?? $record['inprogress_end'],

          'class' => $resEncData['class'],
        ];

        print_r($payloadEncounterFinish);

        // =====================================================
        // DEBUG
        // =====================================================

        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(SshtApiUrl::ENCOUNTER_FINISH),
          payload: $payloadEncounterFinish,
        )) {
          continue;
        }

        // =====================================================
        // SEND ENCOUNTER FINISH
        // =====================================================

        $encounterFinish = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_FINISH,
          [
            'json' => $payloadEncounterFinish,
          ]
        );

        // IMPORTANT:
        // sebelumnya pakai $resEncReq di sini.
        // Itu response GET Encounter, bukan response FINISH.
        $resFinish = json_decode(
          (string) $encounterFinish->getBody(),
          true
        );

        print_r("Response Form body:\n");
        print_r($resFinish);

        if (
          $encounterFinish->getStatusCode() == 200 ||
          $encounterFinish->getStatusCode() == 201
        ) {

          $encData = $resFinish['data'] ?? [];

          $finishEncounterIhsId =
            $encData['idIHS'] ?? $encounterIhsId;

          echo " SUCCESS ENCOUNTER: {$finishEncounterIhsId}\n";

          // =================================================
          // UPDATE LOCAL ENCOUNTER
          // =================================================

          $this->dbLocal
            ->createCommand()
            ->update(
              'ssht_encounter',
              [
                'idIHS' => $finishEncounterIhsId,

                'inprogress_end' => $encData['inprogress_end'] ?? null,

                'finish_start' => $encData['finish_start'] ?? null,

                'finish_end' => $encData['finish_end'] ?? null,

                'updated_at' => date('Y-m-d H:i:s'),

                'class' => $encData['class'] ?? $record['class'],
              ],
              [
                'idIHS' => $encounterIhsId,
              ]
            )
            ->execute();
        } else {

          echo " FAILED ENCOUNTER\n";
          sleep(2);
        }
      } catch (Exception $e) {

        echo " ERROR: {$e->getMessage()}\n";
        sleep(5);
      }

      sleep(2);
    }

    echo "\n--- TASK DONE ---\n";
    // end actionSendEncounterFinishRalanSingle($tgl_param, $rm_param)
  }

  public function sendFinishRalanSingleLocalRank(
    string $tgl_param,
    string $rm_param
  ): void {
    echo "--- TASK SSHT EncounterFinish (LOCAL RANK) START: {$tgl_param} ---\n";

    $encounters = (new Query())
      ->select([
        'idIHS',
        'subject_rm',
        'subject_idIHS',
        'subject_nama',
        'practition_lokalid',
        'practition_idIHS',
        'practition_nama',
        'location_idIHS',
        'location_nama',
        'arrived_start',
        'arrived_end',
        'inprogress_start',
        'inprogress_end',
        'finish_start',
        'finish_end',
        'class',
      ])
      ->from('ssht_encounter')
      ->where([
        'like',
        'arrived_start',
        $tgl_param . '%',
        false,
      ])
      ->andWhere([
        'subject_rm' => $rm_param,
      ])
      ->andWhere([
        'class' => 'AMB',
      ])
      ->all($this->dbLocal);

    foreach ($encounters as $record) {

      try {

        // =====================================================
        // GET ENCOUNTER
        // =====================================================

        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::ENCOUNTER_GET
          ),
          payload: $record['idIHS'],
        )) {
          continue;
        }

        $resEncReq = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_GET,
          [
            'query' => [
              'id' => $record['idIHS'],
            ],
          ]
        );

        $resEnc = json_decode(
          (string) $resEncReq->getBody(),
          true
        );

        print_r($resEnc);

        if (
          $resEncReq->getStatusCode() != 200 ||
          ($resEnc['status'] ?? 'false') !== 'true'
        ) {
          continue;
        }

        $encData = $resEnc['data'];
        $encounterIhsId = $encData['idIHS'];

        // =====================================================
        // GET CONDITION LOCAL
        // =====================================================

        $getConditionLocal = (new Query())
          ->select([
            'condition_idIHS',
            'encounter_idIHS',
            'code',
            'display',
            'conditionRank',
          ])
          ->from('ssht_condition')
          ->where([
            'encounter_idIHS' => $encounterIhsId,
          ])
          ->orderBy(
            'CAST(conditionRank AS UNSIGNED) ASC'
          )
          ->all($this->dbLocal);

        // =====================================================
        // BUILD DIAGNOSIS
        // =====================================================

        $diagnosis = array_map(
          function ($item) {
            return [
              'condition_idIHS' =>
              $item['condition_idIHS'],

              'code' =>
              $item['code'],

              'display' =>
              $item['display'],

              'conditionRank' =>
              (string) $item['conditionRank'],
            ];
          },
          $getConditionLocal
        );

        echo "\nDiagnosis (LOCAL RANK):\n";
        print_r($diagnosis);

        // =====================================================
        // BUILD FINISH PAYLOAD
        // =====================================================

        $payloadEncounterFinish = [
          'encounter_idIHS' => $encounterIhsId,

          'rm' => $record['subject_rm'],
          'patient_idIHS' => $record['subject_idIHS'],
          'patient_nama' => $record['subject_nama'],

          'practition_idIHS' =>
          $record['practition_idIHS'],

          'practition_nama' =>
          $record['practition_nama'],

          'location_idIHS' =>
          $record['location_idIHS'],

          'location_nama' =>
          $record['location_nama'],

          'diagnosis' => $diagnosis,

          'arrived_start' =>
          $record['arrived_start'],

          'arrived_end' =>
          $record['arrived_end'],

          'inprogress_start' =>
          $record['inprogress_start'],

          'inprogress_end' =>
          $record['inprogress_end'],

          'finish_start' =>
          $record['finish_start']
            ?? $record['inprogress_end'],

          'finish_end' =>
          $record['finish_end']
            ?? $record['inprogress_end'],

          'class' => $encData['class'],
        ];

        print_r($payloadEncounterFinish);

        // =====================================================
        // DEBUG
        // =====================================================

        if (!$this->debugger->allow(
          context: SshtApiUtil::genDebugContext(
            SshtApiUrl::ENCOUNTER_FINISH
          ),
          payload: $payloadEncounterFinish,
        )) {
          continue;
        }

        // =====================================================
        // SEND FINISH
        // =====================================================

        $encounterFinish = SshtApiBase::request(
          SshtApiUrl::ENCOUNTER_FINISH,
          [
            'json' => $payloadEncounterFinish,
          ]
        );

        $resFinish = json_decode(
          (string) $encounterFinish->getBody(),
          true
        );

        echo "\nResponse Encounter Finish:\n";
        print_r($resFinish);

        // =====================================================
        // SAVE RESULT
        // =====================================================

        if (
          $encounterFinish->getStatusCode() == 200 ||
          $encounterFinish->getStatusCode() == 201
        ) {

          $this->dbLocal
            ->createCommand()
            ->update(
              'ssht_encounter',
              [
                'finish_start' =>
                $resFinish['data']['finish_start']
                  ?? $record['finish_start'],

                'finish_end' =>
                $resFinish['data']['finish_end']
                  ?? $record['finish_end'],

                'updated_at' =>
                date('Y-m-d H:i:s'),
              ],
              [
                'idIHS' => $encounterIhsId,
              ]
            )
            ->execute();

          echo "SUCCESS ENCOUNTER FINISH: {$encounterIhsId}\n";
        } else {

          echo "FAILED ENCOUNTER FINISH\n";
        }
      } catch (\Throwable $e) {

        echo "ERROR: {$e->getMessage()}\n";
      }

      sleep(2);
    }

    echo "\n--- TASK DONE ---\n";
  }

  /**
   * Generate waktu Encounter Ralan.
   */
  private function generateEncounterTimes(string $a_end): array
  {
    $ts_end = strtotime($a_end);

    $ts_arrived = $ts_end - (rand(15, 30) * 60);

    $ts_inprogress = $ts_end - (rand(5, 10) * 60);

    return [
      'arrived_at' => date(
        'Y-m-d H:i:s',
        $ts_arrived
      ),

      'inprogress_at' => date(
        'Y-m-d H:i:s',
        $ts_inprogress
      ),

      'inprogress_end' => date(
        'Y-m-d H:i:s',
        $ts_end
      ),
    ];
  }

  private function generateEncounterTimesUgd($a_end)
  {
    $ts_arrived = strtotime($a_end);

    // Durasi triase 3-5 menit
    $ts_triaged_end = $ts_arrived + (rand(3, 5) * 60);

    // Durasi in-progress sekitar 20 menit
    $ts_inprogress_end = $ts_triaged_end + (rand(18, 25) * 60);

    return [
      'arrived_at' => date('Y-m-d H:i:s', $ts_arrived),
      'arrived_end' => date('Y-m-d H:i:s', $ts_arrived),

      'triaged_at' => date('Y-m-d H:i:s', $ts_arrived),
      'triaged_end' => date('Y-m-d H:i:s', $ts_triaged_end),

      'inprogress_at' => date('Y-m-d H:i:s', $ts_triaged_end),
      'inprogress_end' => date('Y-m-d H:i:s', $ts_inprogress_end),
    ];
  }
}
