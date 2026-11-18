-- Run once in the portal database after prototype_course_features.sql.
-- Re-running this script does not duplicate the TESDA main office or archive table.

INSERT INTO training_centers (center_code, name, region, province, city, is_active)
SELECT 'TESDA-MAIN', 'TESDA Main Office', 'NCR', NULL, NULL, 1
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM training_centers WHERE center_code = 'TESDA-MAIN'
);

CREATE TABLE IF NOT EXISTS document_archive (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    training_center_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NULL,
    storage_key CHAR(40) NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    file_size INT UNSIGNED NOT NULL,
    ocr_text MEDIUMTEXT NOT NULL,
    ocr_error VARCHAR(1000) NULL,
    image_label VARCHAR(100) NULL,
    text_label VARCHAR(100) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY document_archive_storage_key_unique (storage_key),
    KEY document_archive_center_created_idx (training_center_id, created_at),
    KEY document_archive_uploaded_by_idx (uploaded_by),
    CONSTRAINT document_archive_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON DELETE RESTRICT,
    CONSTRAINT document_archive_user_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
