<?php

namespace common\services\SshtApiGwClient;

// use Exception;
// use Yii;

class SshtApiUrl
{

  // Patients URL
  /**
   * Get Patient by NIK.
   * 
   * Method: GET
   * URL: api/v1/ssht/resource/patients/get
   * Query Params:
   * - id (required) : string|numeric - Format (34XXXXXXXXXXXXXX)
   */
  public const PATIENTS_GET_BY_NIK = ['GET', 'ssht/resource/patients/get'];
  // public const PATIENTS_GET_BY_IHS = "";

  /**
   * Get Patient by data NIK.
   *
   * param: nik, nama & birthdate
   * 
   * Method: POST 
   * URL: api/v1/ssht/resource/patients/data-nik
   * Body JSON:
   * {
   *    "nik": "string|numeric (required) - nik"
   *    "nama": "string (required) - nama"
   *    "birthdate": "string|date (required) - birthdate"
   * }
   */
  public const PATIENTS_GET_DATA = ['POST', 'ssht/resource/patients/data-nik'];

  /**
   * Get Patient by data Gender.
   *
   * param: nik, nama & gender 
   * 
   * Method: POST 
   * URL: api/v1/ssht/resource/patients/data-nik
   * Body JSON:
   * {
   *    "nik": "string|numeric (required) - nik"
   *    "nama": "string (required) - nama"
   *    "gender": "string (required) - gender ['male' or 'female']"
   * }
   */
  public const PATIENTS_GET_DATA_GENDER = ['POST', 'ssht/resource/patients/data-gender'];

  // Practition URL
  /**
   * Get Practition by NIK.
   * 
   * Method: GET
   * URL: api/v1/ssht/resource/practitioner/get
   * Query Params:
   * - id (required) : string|numeric - Format (34XXXXXXXXXXXXXX)
   */
  public const PRACTITION_GET_BY_NIK = ['GET', "ssht/resource/practitioner/get"];

  /**
   * Get Practition by idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/resource/practitioner/get
   * Query Params:
   * - id (required) : string|numeric - Format (XXXXXXXXXX)
   */
  public const PRACTITION_GET_BY_IHS = ['GET', "ssht/resource/practitioner/getbyihs"];
  // public const PRACTITION_CEK_LOCAL = "ssht/resource/practitioner/select";
  // public const PRACTITION_GET_DATA = "ssht/resource/practitioner/data";

  // Organization URL
  public const ORGANIZATION_GET_ALL = "ssht/resource/organization/all";
  public const ORGANIZATION_GET_BY_IHS = "ssht/resource/organization/get";
  public const ORGANIZATION_CREATE = "ssht/resource/organization";
  public const ORGANIZATION_UPDATE = "ssht/resource/organization/update";

  // Location URL
  public const LOCATION_GET_ALL = "ssht/resource/location/all";

  /**
   * Get Location by location_idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/resource/location/get
   * Query Params:
   * - id (required) : UUID4 - Format (Example: d99eb2ec-889e-80d6-9976-4e0113c5401b)
   */
  public const LOCATION_GET_BY_IHS = ['GET', 'ssht/resource/location/get'];
  public const LOCATION_CREATE = "ssht/resource/location";
  public const LOCATION_UPDATE = "ssht/resource/location/update";
  // public const LOCATION_CEK_LOCAL = "ssht/resource/location/update";

  // Encounter URL
  /**
   * Get Encounter by encounter_idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/encounter/get
   * Query Params:
   * - id (required) : UUID4 - Format (Example: d99eb2ec-889e-80d6-9976-4e0113c5401b)
   */
  public const ENCOUNTER_GET = ['GET', 'ssht/encounter/get'];

  /**
   * Create Encounter.
   *
   * Create Encounter auto inprogress_start for now..
   * 
   * Method: POST
   * URL: api/v1/ssht/encounter/create
   * Body JSON:
   * {
   *    "pasien_idIHS": "string (required) - ID IHS pasien",
   *    "pasien_nama": "string (required) - nama pasien",
   *    "pasien_rm": "string (required) - nomor rekam medis pasien",
   *    "practitioner_idIHS": "string (required) - ID IHS practitioner",
   *    "practitioner_nama": "string (required) - nama practitioner",
   *    "location_idIHS": "string (required) - ID IHS lokasi",
   *    "location_nama": "string (required) - nama lokasi",
   *    "location_poli": "string (required) - poli / unit layanan",
   *    "arrived_at": "string (required|date) - waktu pasien datang (format: YYYY-MM-DD HH:mm:ss)",
   *    "inprogress_at": "string (required|date) - waktu mulai pelayanan (format: YYYY-MM-DD HH:mm:ss)",
   *    "class": "string (required) - kelas encounter ['ralan', 'igd', 'ranap']"
   * }
   */
  public const ENCOUNTER_CREATE = ['POST', 'ssht/encounter/create'];
  public const ENCOUNTER_UPDATE = "ssht/encounter/update-patch";
  public const ENCOUNTER_UPDATE_INPROGRESS = "ssht/encounter/inprogres-update";
  public const ENCOUNTER_FINISH = "ssht/encounter/finish";

  // Condition URL (DIAGNOSA)
  /**
   * Get Condition by condition_idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/condition/get
   * Query Params:
   * - id (required) : UUID4 - Format (Example: d99eb2ec-889e-80d6-9976-4e0113c5401b)
   */
  public const CONDITION_GET = "ssht/condition/get";

  /**
   * Get Condition by encounter_idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/condition/get-en
   * Query Params:
   * - id (required) : UUID4 - Format (Example: d99eb2ec-889e-80d6-9976-4e0113c5401b)
   */
  public const CONDITION_GET_BY_ENCOUNTER = ['GET', 'ssht/condition/get-en'];

  /**
   * Create Condition.
   * 
   * Method: POST
   * URL: api/v1/ssht/condition/create
   * Body JSON:
   * {
   *    "encounter_idIHS": "string (required|uuid) - ID IHS encounter",
   *    "patient_idIHS": "string (required|max:50) - ID IHS pasien",
   *    "patient_nama": "string (required|max:60) - nama pasien",
   *    "conditionCode": "string (required) - kode kondisi / diagnosa",
   *    "conditionName": "string (required) - nama kondisi / diagnosa",
   *    "inprogress_start": "string (required|date) - waktu mulai (format: YYYY-MM-DD HH:mm:ss)",
   *    "inprogress_end": "string (required|date) - waktu selesai (format: YYYY-MM-DD HH:mm:ss)"
   * }
   */
  public const CONDITION_CREATE = ['POST', 'ssht/condition/create'];
  public const CONDITION_UPDATE = "ssht/condition/update";
  public const CONDITION_UPDATE_RMNDOK = "ssht/condition/get-rmndok";

  // Observation
  public const OBSERVATION_GET = "ssht/observation/get";
  public const OBSERVATION_GET_BY_ENCOUNTER = "ssht/observation/get-en";
  // public const OBSERVATION_CREATE = "ssht/observation/";
  public const OBSERVATION_CREATE_VITAL = "ssht/observation/vital/create";
  public const OBSERVATION_CREATE_KESADARAN = "ssht/observation/kesadaran/create";
  // public const OBSERVATION_UPDATE = "";
  public const OBSERVATION_UPDATE_VITAL = "ssht/observation/vital/update";
  public const OBSERVATION_UPDATE_KESADARAN = "ssht/observation/kesadaran/update";
  public const OBSERVATION_UPDATE_RMNDOK = "ssht/observation/get-rmndok";

  // Compostition
  public const COMPOSITION_GET = "ssht/composition/get";
  public const COMPOSITION_CREATE = "ssht/composition/create";
  public const COMPOSITION_UPDATE = "ssht/composition/update";

  // Allergy Intolerant
  public const ALLERGY_GET = "ssht/allergy/get";
  public const ALLERGY_CREATE = "ssht/allergy/create";
  public const ALLERGY_UPDATE = "ssht/allergy/update";

  // Service Request
  public const SERVICE_REQUEST_GET = "ssht/service-request/get";
  public const SERVICE_REQUEST_CREATE = "ssht/service-request/create";
  public const SERVICE_REQUEST_UPDATE = "ssht/service-request/update";

  // Speciment
  public const SPECIMENT_GET = "ssht/speciment/get";
  public const SPECIMENT_CREATE = "ssht/speciment/create";
  public const SPECIMENT_UPDATE = "ssht/speciment/update";

  // Observation
  public const OBSERVATION_CREATE_LAB = "ssht/lab-obs/create";
  public const OBSERVATION_UPDATE_LAB = "ssht/lab-obs/update";
  public const OBSERVATION_GET_LAB = "ssht/lab-obs/get";

  // Diagnostic Report
  public const DIAGNOSTIC_REPORT_GET = "";
  public const DIAGNOSTIC_REPORT_CREATE = "";
  public const DIAGNOSTIC_REPORT_UPDATE = "";

  // Procedure
  public const PROCEDURE_GET = "";
  public const PROCEDURE_GET_BY_ENCOUNTER = "";
  public const PROCEDURE_CREATE = "";
  public const PROCEDURE_UPDATE = "";
  public const PROCEDURE_UPDATE_RMNDOK = "";

  // Clinical Impression
  public const CLINICAL_IMPRESSION_GET = "";
  public const CLINICAL_IMPRESSION_CREATE = "";
  public const CLINICAL_IMPRESSION_UPDATE = "";

  // Imunization
  /**
   * Get Imunization by imunization_idIHS.
   * 
   * Method: GET
   * URL: api/v1/ssht/imunization/get
   * Query Params:
   * - id (required) : string|numeric - Format (34XXXXXXXXXXXXXX)
   */
  public const IMUNIZATION_GET = ['GET', 'ssht/imunization/get'];

  /**
   * Create Immunization.
   * 
   * Method: POST
   * URL: api/v1/ssht/immunization/create
   * Body JSON:
   * {
   *    "pasien_idIHS": "string (required|max:60) - ID IHS pasien",
   *    "pasien_nama": "string (required|max:255) - nama pasien",
   *    "petugas_idIHS": "string (required|max:60) - ID IHS petugas",
   *    "location_idIHS": "string (sometimes|uuid) - ID IHS lokasi",
   *    "location_nama": "string (sometimes|max:255) - nama lokasi",
   *    "encounter_idIHS": "string (required|uuid) - ID IHS encounter",
   *    "vacine_data": [
   *       {
   *          "code": "string (required|max:60) - kode vaksin kfa",
   *          "display": "string (required|max:255) - nama vaksin kfa",
   *          "system": "string (required|max:255) - sistem kode vaksin kfa"
   *       },
   *       {
   *          "code": "string (required|max:60) - kode vaksin cvxg",
   *          "display": "string (required|max:255) - nama vaksin cvxg",
   *          "system": "string (required|max:255) - sistem kode vaksin cvxg"
   *       },
   *       {
   *          "code": "string (required|max:60) - kode vaksin cvxn",
   *          "display": "string (required|max:255) - nama vaksin cvxn",
   *          "system": "string (required|max:255) - sistem kode vaksin cvxn"
   *       }
   *    ],
   *    "vacine_lotNumber": "string (required|max:25) - nomor batch vaksin",
   *    "vacine_expiry": "string (required|date) - tanggal kedaluwarsa vaksin (format: YYYY-MM-DD)",
   *    "procedure_time": "string (required|date) - waktu tindakan (format: YYYY-MM-DD HH:mm:ss)",
   *    "procedure_iterasi": "string (sometimes|numeric|max:2) - iterasi tindakan",
   *    "procedure_reason": "string (sometimes) - alasan imunisasi (RuleImunizationReason)"
   * }
   */
  public const IMUNIZATION_CREATE = ['POST', 'ssht/imunization/create'];

  /**
   * Update[PATCH] Immunization.
   * 
   * Method: POST
   * URL: api/v1/ssht/immunization/patch
   * 
   * Body JSON:
   * {
   *    "id": "string (required|uuid) - ID imunisasi"
   *    "schema": "string (required) - nama field / skema yang akan diupdate [Exp: '/status']",
   *    "value": "mixed (required) - nilai baru untuk field tersebut [Exp: 'entered-in-error']"
   * }
   */
  public const IMUNIZATION_PATCH = ['POST', 'ssht/imunization/patch'];

  // MEDICATION_REQUEST

  // MEDICATION_DISPENSE

  // MISC 
  public const CONSENT_CREATE = "ssht/misc/consent/create";
  public const CONSENT_CEK = "ssht/misc/consent";

  // MISC - KYC
  public const KYC_GENERATE = "ssht/misc/kyc";
  public const KYC_STATUS = "ssht/misc/kyc/cek";

  // MISC - KFA

  /**
   * Get kfa data product by keyword.
   * 
   * Method: POST
   * URL: api/v1/ssht/misc/kfa/product/all
   * Body JSON:
   * {
   *    "page": "string|numeric (required) - page"
   *    "product_type": "string (optional) - product_type [example: ]"
   *    "farmalkes_type": "string (optional) - farmalkes_type [example: ]"
   *    "keyword": "string (required) - keyword ['acarbose']"
   * }
   */
  public const KFA_ALL = "ssht/misc/kfa/product/all";
  public const KFA_PRICE = "ssht/misc/kfa/product/price";

  /**
   * Get kfa data detail product.
   * 
   * Method: POST
   * URL: api/v1/ssht/misc/kfa/product/detail
   * Body JSON:
   * {
   *    "id": "string (required) - id"
   *    "kategori": "string (required) - kategori ['nie', 'lkpp', 'kfa']"
   * }
   */
  public const KFA_DETAIL = ["POST", "ssht/misc/kfa/product/detail"];

  // MISC - DICOM get dicomrouter docker-compose.yml
  public const DICOM = "ssht/misc/dicom-router/get-template";

  // MISC - DASHBOARD LOG
  /**
   * Get summary data for dashboard view.
   * 
   * Method: GET
   * URL: api/v1/ssht/misc/dashboard-look/index
   * Query Params:
   * - page (optional) : integer - Current page number for pagination
   * - date_range (optional) : string - Format (YYYY-MM-DD to YYYY-MM-DD)
   */
  public const DASHBOARD_LOOK_INDEX = ['GET', 'api/v1/ssht/misc/dashboard-look/index'];
  /**
   * Get detailed data for a specific dashboard item.
   * 
   * Method: POST
   * URL: api/v1/ssht/misc/dashboard-look/view
   * Body JSON:
   * {
   *   "encounter_idIHS": "uuid (required) - UUID encounter item",
   * }
   */
  public const DASHBOARD_LOOK_DETAIL = ['POST', 'api/v1/ssht/misc/dashboard-look/view'];
}
