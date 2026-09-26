CREATE TABLE rd_foto_orthanc (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,

    rd_foto_id BIGINT NOT NULL,
    noradio VARCHAR(100) NOT NULL,
    accession_number VARCHAR(100) NOT NULL,

    orthanc_patient_id VARCHAR(100) NULL,
    orthanc_study_id VARCHAR(100) NULL,
    orthanc_series_id VARCHAR(100) NULL,
    orthanc_instance_id VARCHAR(100) NULL,

    study_instance_uid VARCHAR(255) NOT NULL,
    series_instance_uid VARCHAR(255) NOT NULL,
    sop_instance_uid VARCHAR(255) NOT NULL,

    status TINYINT NOT NULL DEFAULT 0
        COMMENT '0=pending,1=success,2=error',

    error_message TEXT NULL,

    created_at DATETIME NULL,
    updated_at DATETIME NULL,

    UNIQUE KEY uq_rd_foto_id (rd_foto_id),
    UNIQUE KEY uq_sop_instance_uid (sop_instance_uid),

    KEY idx_noradio (noradio),
    KEY idx_accession (accession_number),
    KEY idx_study_uid (study_instance_uid),
    KEY idx_series_uid (series_instance_uid),
    KEY idx_status (status)
);

-- /** start alter **/
-- /** misal udah terlanjur ada tambahin kolom ini **/
-- ALTER TABLE rd_foto_orthanc
-- ADD COLUMN source_sequence INT NULL AFTER noradio;
-- 
-- ALTER TABLE rd_foto_orthanc
-- MODIFY rd_foto_id BIGINT NULL;
--  /** end alter **/

-- /** kalo belom ada.. **/
-- CREATE TABLE rd_foto_orthanc (
--     id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
--
--     noradio VARCHAR(100) NOT NULL,
--     source_sequence INT NOT NULL,
--
--     -- Optional reference ke legacy rd_foto.
--     -- NULL kalau sumbernya dari sharing.
--     rd_foto_id BIGINT NULL,
--
--     accession_number VARCHAR(100) NOT NULL,
--
--     orthanc_patient_id VARCHAR(100) NULL,
--     orthanc_study_id VARCHAR(100) NULL,
--     orthanc_series_id VARCHAR(100) NULL,
--     orthanc_instance_id VARCHAR(100) NULL,
--
--     study_instance_uid VARCHAR(255) NULL,
--     series_instance_uid VARCHAR(255) NULL,
--     sop_instance_uid VARCHAR(255) NULL,
--
--     status TINYINT NOT NULL DEFAULT 0
--         COMMENT '0=pending,1=success,2=error',
--
--     error_message TEXT NULL,
--
--     created_at DATETIME NULL,
--     updated_at DATETIME NULL,
--
--     UNIQUE KEY uq_noradio_sequence (
--         noradio,
--         source_sequence
--     ),
--
--     UNIQUE KEY uq_sop_instance_uid (
--         sop_instance_uid
--     ),
--
--     KEY idx_noradio (noradio),
--     KEY idx_accession (accession_number),
--     KEY idx_rd_foto_id (rd_foto_id),
--     KEY idx_status (status),
--     KEY idx_study_uid (study_instance_uid),
--     KEY idx_series_uid (series_instance_uid)
-- );
--  /** end kalo belum ada.. **/
