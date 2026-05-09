<?php

namespace common\services\SshtApiGwClient\mapping;

/**
 * interface SshtApiQueryRepository
 *
 * consumed SshtApiQueryMapping untuk maping logic query select insternal simrs
 *
 */
interface SshtApiQueryRepository
{
  // query-encounter
  public static function queryEncounterRalanSimrs(string $tgl_param);
  public static function queryEncounterRanapSimrs(string $tgl_param);
  public static function queryEncounterUgdSimrs(string $tgl_param);

  // query-servicerequest
  public static function queryServiceRequestSimrsRadio(
    string $encounter_class,
    string $rm,
    string $tgl_param,
    $noregis = null
  );

  // query-observation-diagnosticreport RAD
  // observation & diagnostic report RAD
  public static function queryObservationDanDiagnosticReportSimrsRadio(string $noradio);
}
