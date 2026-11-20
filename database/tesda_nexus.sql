-- =====================================================================
--  TESDA NEXUS — full database (schema + demo data)
--  An AI and Blockchain-Based Management System for TESDA Region IV-B
--
--  How to run: create your database first, select it, then execute this
--  whole script in HeidiSQL / phpMyAdmin / MySQL Workbench.
--    e.g.  CREATE DATABASE tesda_nexus CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
--          USE tesda_nexus;
--
--  Works on MySQL 5.7 and 8.x. CHECK constraints are only enforced on
--  MySQL 8.0.16+ (5.7 accepts them but ignores them).
--
--  WARNING: this script DROPS and recreates every table below in the
--  selected database. Run it on a fresh database, or one you are happy
--  to reset.
--
--  Users and roles (users.role):
--    admin         Main Office Administrator
--    center_admin  Learning Center Administrator
--    instructor    Teacher
--    student       Student
--
--  Every demo account's password is:  Tesda@2026
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS blockchain_verifications;
DROP TABLE IF EXISTS blockchain_ledger;
DROP TABLE IF EXISTS document_access_logs;
DROP TABLE IF EXISTS document_search_results;
DROP TABLE IF EXISTS document_search_messages;
DROP TABLE IF EXISTS document_search_sessions;
DROP TABLE IF EXISTS document_ai_predictions;
DROP TABLE IF EXISTS document_pages;
DROP TABLE IF EXISTS activity_logs;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS allowance_request_students;
DROP TABLE IF EXISTS allowance_requests;
DROP TABLE IF EXISTS stock_movements;
DROP TABLE IF EXISTS inventory_request_items;
DROP TABLE IF EXISTS inventory_requests;
DROP TABLE IF EXISTS delivery_personnel;
DROP TABLE IF EXISTS products;
DROP TABLE IF EXISTS announcements;
DROP TABLE IF EXISTS student_documents;
DROP TABLE IF EXISTS requirement_types;
DROP TABLE IF EXISTS document_archive;
DROP TABLE IF EXISTS ai_models;
DROP TABLE IF EXISTS document_categories;
DROP TABLE IF EXISTS center_applications;
DROP TABLE IF EXISTS batch_students;
DROP TABLE IF EXISTS batches;
DROP TABLE IF EXISTS enrollments;
DROP TABLE IF EXISTS course_materials;
DROP TABLE IF EXISTS courses;
DROP TABLE IF EXISTS student_profiles;
DROP TABLE IF EXISTS refresh_tokens;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS training_centers;

SET FOREIGN_KEY_CHECKS = 1;


-- =====================================================================
--  1. ORGANIZATION AND ACCOUNTS
-- =====================================================================

-- The TESDA main office and every learning center.
-- center_code 'TESDA-MAIN' marks the main office (the backend checks it).
CREATE TABLE training_centers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    center_code   VARCHAR(40)  NOT NULL,
    name          VARCHAR(150) NOT NULL,
    center_type   VARCHAR(20)  NOT NULL DEFAULT 'learning_center',
    region        VARCHAR(100) NULL,
    province      VARCHAR(100) NULL,
    city          VARCHAR(100) NULL,
    address       VARCHAR(255) NULL,
    contact_email VARCHAR(255) NULL,
    contact_phone VARCHAR(30)  NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY training_center_code_unique (center_code),
    KEY training_center_active_idx (is_active),
    CONSTRAINT training_center_type_chk CHECK (center_type IN ('main_office', 'learning_center'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every person who can log in. training_center_id is where they belong
-- (NULL for the main office administrator).
CREATE TABLE users (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    username           VARCHAR(100) NOT NULL,
    full_name          VARCHAR(150) NULL,
    email              VARCHAR(255) NOT NULL,
    password           VARCHAR(255) NOT NULL,
    role               VARCHAR(20)  NOT NULL DEFAULT 'student',
    training_center_id INT UNSIGNED NULL,
    contact_number     VARCHAR(30)  NULL,
    is_active          TINYINT(1) UNSIGNED NOT NULL DEFAULT 1,
    last_login_at      DATETIME     NULL,
    created_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         DATETIME     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY username_unique (username),
    UNIQUE KEY email_unique (email),
    KEY role_idx (role),
    KEY user_training_center_idx (training_center_id),
    CONSTRAINT user_role_chk CHECK (role IN ('admin', 'center_admin', 'instructor', 'student')),
    CONSTRAINT user_training_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Login refresh tokens (used by the API auth).
CREATE TABLE refresh_tokens (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id    INT UNSIGNED NOT NULL,
    token      TEXT         NOT NULL,
    jti        TEXT         NOT NULL,
    expires_at DATETIME     NOT NULL,
    PRIMARY KEY (id),
    KEY user_id_idx (user_id),
    CONSTRAINT refresh_token_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Extra details for student accounts: student number, personal info,
-- and the bank account used for allowance releases.
-- created_by is the teacher or center admin who added the student.
CREATE TABLE student_profiles (
    user_id             INT UNSIGNED NOT NULL,
    student_number      VARCHAR(40)  NOT NULL,
    first_name          VARCHAR(80)  NOT NULL,
    middle_name         VARCHAR(80)  NULL,
    last_name           VARCHAR(80)  NOT NULL,
    birthdate           DATE         NULL,
    sex                 VARCHAR(10)  NULL,
    address             VARCHAR(255) NULL,
    guardian_name       VARCHAR(150) NULL,
    guardian_contact    VARCHAR(30)  NULL,
    bank_name           VARCHAR(100) NULL,
    bank_account_number VARCHAR(40)  NULL,
    bank_account_holder VARCHAR(150) NULL,
    created_by          INT UNSIGNED NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    UNIQUE KEY student_number_unique (student_number),
    KEY student_profile_created_by_idx (created_by),
    CONSTRAINT student_sex_chk CHECK (sex IS NULL OR sex IN ('male', 'female')),
    CONSTRAINT student_profile_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT student_profile_created_by_fk FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  2. PROGRAMS, BATCHES AND ENROLLMENT  (Teachers)
-- =====================================================================

-- Programs / NC courses offered by a learning center.
CREATE TABLE courses (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    training_center_id INT UNSIGNED NOT NULL,
    course_code        VARCHAR(40)  NOT NULL,
    course_name        VARCHAR(150) NOT NULL,
    qualification      VARCHAR(30)  NOT NULL DEFAULT 'NC II',
    description        TEXT         NULL,
    class_days         VARCHAR(150) NULL,
    class_schedule     TEXT         NULL,
    course_outline     TEXT         NULL,
    duration_hours     INT UNSIGNED NULL,
    capacity           INT UNSIGNED NOT NULL DEFAULT 30,
    status             VARCHAR(20)  NOT NULL DEFAULT 'open',
    created_at         TIMESTAMP    NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY course_center_code_unique (training_center_id, course_code),
    KEY course_training_center_idx (training_center_id),
    KEY course_status_idx (status),
    CONSTRAINT course_status_chk CHECK (status IN ('open', 'closed', 'archived')),
    CONSTRAINT course_capacity_chk CHECK (capacity > 0),
    CONSTRAINT course_training_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Learning materials a teacher attaches to a course.
CREATE TABLE course_materials (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    course_id    INT UNSIGNED  NOT NULL,
    uploaded_by  INT UNSIGNED  NULL,
    title        VARCHAR(150)  NOT NULL,
    description  VARCHAR(500)  NULL,
    resource_url VARCHAR(2048) NOT NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY course_material_course_idx (course_id),
    KEY course_material_user_idx (uploaded_by),
    CONSTRAINT course_material_course_fk FOREIGN KEY (course_id)
        REFERENCES courses (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT course_material_user_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A student's enrollment in a course (the backend's current enrollment list).
CREATE TABLE enrollments (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_id    INT UNSIGNED NOT NULL,
    course_id     INT UNSIGNED NOT NULL,
    instructor_id INT UNSIGNED NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'enrolled',
    enrolled_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY student_course_unique (student_id, course_id),
    KEY enrollment_course_idx (course_id),
    KEY enrollment_instructor_idx (instructor_id),
    CONSTRAINT enrollment_status_chk CHECK (status IN ('enrolled', 'completed', 'dropped')),
    CONSTRAINT enrollment_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT enrollment_course_fk FOREIGN KEY (course_id)
        REFERENCES courses (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT enrollment_instructor_fk FOREIGN KEY (instructor_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A batch (class) of a course, run by a teacher.
CREATE TABLE batches (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    course_id     INT UNSIGNED NOT NULL,
    instructor_id INT UNSIGNED NULL,
    batch_number  SMALLINT UNSIGNED NOT NULL,
    batch_name    VARCHAR(100) NOT NULL,
    start_date    DATE         NULL,
    end_date      DATE         NULL,
    capacity      INT UNSIGNED NOT NULL DEFAULT 25,
    status        VARCHAR(20)  NOT NULL DEFAULT 'upcoming',
    created_by    INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY batch_course_number_unique (course_id, batch_number),
    KEY batch_instructor_idx (instructor_id),
    KEY batch_status_idx (status),
    KEY batch_created_by_idx (created_by),
    CONSTRAINT batch_status_chk CHECK (status IN ('upcoming', 'ongoing', 'completed', 'cancelled')),
    CONSTRAINT batch_dates_chk CHECK (end_date IS NULL OR start_date IS NULL OR end_date >= start_date),
    CONSTRAINT batch_capacity_chk CHECK (capacity > 0),
    CONSTRAINT batch_course_fk FOREIGN KEY (course_id)
        REFERENCES courses (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT batch_instructor_fk FOREIGN KEY (instructor_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT batch_created_by_fk FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which students are in which batch.
CREATE TABLE batch_students (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id    INT UNSIGNED NOT NULL,
    student_id  INT UNSIGNED NOT NULL,
    status      VARCHAR(20)  NOT NULL DEFAULT 'active',
    assigned_by INT UNSIGNED NULL,
    assigned_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY batch_student_unique (batch_id, student_id),
    KEY batch_student_student_idx (student_id),
    KEY batch_student_assigned_by_idx (assigned_by),
    CONSTRAINT batch_student_status_chk CHECK (status IN ('active', 'completed', 'dropped')),
    CONSTRAINT batch_student_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT batch_student_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT batch_student_assigned_by_fk FOREIGN KEY (assigned_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- People applying online to join a center as a student or instructor.
CREATE TABLE center_applications (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    training_center_id INT UNSIGNED NOT NULL,
    applied_role       VARCHAR(20)  NOT NULL,
    status             VARCHAR(20)  NOT NULL DEFAULT 'pending',
    applied_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_by        INT UNSIGNED NULL,
    reviewed_at        TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY center_application_user_unique (user_id),
    KEY center_application_queue_idx (training_center_id, status, applied_at),
    KEY center_application_reviewer_idx (reviewed_by),
    CONSTRAINT center_application_role_chk CHECK (applied_role IN ('student', 'instructor')),
    CONSTRAINT center_application_status_chk CHECK (status IN ('pending', 'approved', 'rejected')),
    CONSTRAINT center_application_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT center_application_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT center_application_reviewer_fk FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  3. AI (document classification + OCR) AND DOCUMENTS
--
--  How the AI works in this system (Back-End/ai/inference.py):
--    1. Image classifier: custom CNN ("DocumentCNN", 16 classes) labels
--       the document image and gives a confidence + top predictions.
--    2. OCR: Tesseract reads the text (PyMuPDF for PDFs up to 20 pages;
--       embedded PDF text is used when present, scanned pages go to OCR).
--    3. Text classifier: TF-IDF + LinearSVC labels the OCR text
--       (no confidence score).
--    4. Document finder: the chat-style assistant searches file names,
--       OCR text and AI labels locally to help users find documents.
-- =====================================================================

-- The 16 document types both AI models can predict (labels from the
-- model metadata files). image_label / text_label point here.
CREATE TABLE document_categories (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code         VARCHAR(100) NOT NULL,
    display_name VARCHAR(100) NOT NULL,
    description  VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY document_category_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The AI models / engines in use, with their reported accuracy.
CREATE TABLE ai_models (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    model_code       VARCHAR(50)  NOT NULL,
    model_type       VARCHAR(30)  NOT NULL,
    name             VARCHAR(150) NOT NULL,
    architecture     VARCHAR(100) NULL,
    file_path        VARCHAR(255) NULL,
    num_classes      SMALLINT UNSIGNED NULL,
    accuracy_percent DECIMAL(5,2) NULL,
    evaluated_on     INT UNSIGNED NULL,
    notes            VARCHAR(500) NULL,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ai_model_code_unique (model_code),
    KEY ai_model_type_idx (model_type, is_active),
    CONSTRAINT ai_model_type_chk CHECK (model_type IN ('image_classifier', 'text_classifier', 'ocr_engine')),
    CONSTRAINT ai_model_accuracy_chk CHECK (accuracy_percent IS NULL OR (accuracy_percent >= 0 AND accuracy_percent <= 100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Main office official documents (Main Office Administrator).
-- Filled by the archive upload: OCR text + both AI labels.
-- file_hash is the SHA-256 of the file; it is what gets written to the
-- blockchain ledger so a document can later be checked for tampering.
-- FULLTEXT index = what the document finder searches.
CREATE TABLE document_archive (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    training_center_id INT UNSIGNED  NOT NULL,
    uploaded_by        INT UNSIGNED  NULL,
    storage_key        CHAR(40)      NOT NULL,
    original_name      VARCHAR(255)  NOT NULL,
    mime_type          VARCHAR(100)  NOT NULL,
    file_size          INT UNSIGNED  NOT NULL,
    file_hash          CHAR(64)      NULL,
    pages_analyzed     SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    text_source        VARCHAR(20)   NULL,
    ocr_text           MEDIUMTEXT    NOT NULL,
    ocr_error          VARCHAR(1000) NULL,
    ocr_word_count     INT UNSIGNED  NULL,
    image_label        VARCHAR(100)  NULL,
    image_confidence   DECIMAL(5,2)  NULL,
    text_label         VARCHAR(100)  NULL,
    category           VARCHAR(100)  NULL,
    status             VARCHAR(20)   NOT NULL DEFAULT 'current',
    access_level       VARCHAR(20)   NOT NULL DEFAULT 'internal',
    expires_at         DATE          NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY document_archive_storage_key_unique (storage_key),
    KEY document_archive_center_created_idx (training_center_id, created_at),
    KEY document_archive_uploaded_by_idx (uploaded_by),
    KEY document_archive_status_idx (status),
    KEY document_archive_image_label_idx (image_label),
    KEY document_archive_text_label_idx (text_label),
    KEY document_archive_hash_idx (file_hash),
    FULLTEXT KEY document_archive_search_ft (original_name, ocr_text),
    CONSTRAINT document_archive_status_chk CHECK (status IN ('current', 'in_review', 'incomplete', 'expiring', 'archived')),
    CONSTRAINT document_archive_access_chk CHECK (access_level IN ('public', 'internal', 'restricted')),
    CONSTRAINT document_archive_source_chk CHECK (text_source IS NULL OR text_source IN ('embedded', 'ocr', 'mixed', 'none')),
    CONSTRAINT document_archive_confidence_chk CHECK (image_confidence IS NULL OR (image_confidence >= 0 AND image_confidence <= 100)),
    CONSTRAINT document_archive_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT document_archive_user_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT document_archive_image_label_fk FOREIGN KEY (image_label)
        REFERENCES document_categories (code) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT document_archive_text_label_fk FOREIGN KEY (text_label)
        REFERENCES document_categories (code) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OCR result per page (PDFs are processed page by page, up to 20 pages).
CREATE TABLE document_pages (
    id               INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    document_id      INT UNSIGNED  NOT NULL,
    page_number      SMALLINT UNSIGNED NOT NULL,
    text_source      VARCHAR(20)   NOT NULL DEFAULT 'ocr',
    ocr_text         MEDIUMTEXT    NULL,
    ocr_error        VARCHAR(1000) NULL,
    word_count       INT UNSIGNED  NOT NULL DEFAULT 0,
    image_label      VARCHAR(100)  NULL,
    image_confidence DECIMAL(5,2)  NULL,
    created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY document_page_unique (document_id, page_number),
    KEY document_page_label_idx (image_label),
    FULLTEXT KEY document_page_text_ft (ocr_text),
    CONSTRAINT document_page_number_chk CHECK (page_number BETWEEN 1 AND 20),
    CONSTRAINT document_page_source_chk CHECK (text_source IN ('embedded', 'ocr', 'none')),
    CONSTRAINT document_page_confidence_chk CHECK (image_confidence IS NULL OR (image_confidence >= 0 AND image_confidence <= 100)),
    CONSTRAINT document_page_document_fk FOREIGN KEY (document_id)
        REFERENCES document_archive (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT document_page_label_fk FOREIGN KEY (image_label)
        REFERENCES document_categories (code) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every prediction an AI model made for a document: the CNN's top
-- predictions (rank 1, 2, 3 ... with confidence) and the text model's label.
CREATE TABLE document_ai_predictions (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id     INT UNSIGNED NOT NULL,
    page_number     SMALLINT UNSIGNED NULL,
    model_id        INT UNSIGNED NOT NULL,
    label           VARCHAR(100) NOT NULL,
    confidence      DECIMAL(5,2) NULL,
    prediction_rank TINYINT UNSIGNED NOT NULL DEFAULT 1,
    created_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY ai_prediction_document_idx (document_id, model_id, prediction_rank),
    KEY ai_prediction_model_idx (model_id),
    KEY ai_prediction_label_idx (label),
    CONSTRAINT ai_prediction_confidence_chk CHECK (confidence IS NULL OR (confidence >= 0 AND confidence <= 100)),
    CONSTRAINT ai_prediction_rank_chk CHECK (prediction_rank >= 1),
    CONSTRAINT ai_prediction_document_fk FOREIGN KEY (document_id)
        REFERENCES document_archive (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT ai_prediction_model_fk FOREIGN KEY (model_id)
        REFERENCES ai_models (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ai_prediction_label_fk FOREIGN KEY (label)
        REFERENCES document_categories (code) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Document finder (the chat-style AI assistant). The app keeps this in the
-- PHP session today; these tables keep it so searches can be reviewed.
CREATE TABLE document_search_sessions (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NOT NULL,
    training_center_id INT UNSIGNED NULL,
    started_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_message_at    TIMESTAMP    NULL DEFAULT NULL,
    cleared_at         TIMESTAMP    NULL DEFAULT NULL,
    PRIMARY KEY (id),
    KEY search_session_user_idx (user_id, started_at),
    KEY search_session_center_idx (training_center_id),
    CONSTRAINT search_session_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT search_session_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- One question from the user or one answer from the assistant.
-- search_terms = the keywords the finder pulled out of the question.
CREATE TABLE document_search_messages (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id   INT UNSIGNED NOT NULL,
    sender       VARCHAR(10)  NOT NULL,
    message      TEXT         NOT NULL,
    search_terms VARCHAR(500) NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY search_message_session_idx (session_id, created_at),
    CONSTRAINT search_message_sender_chk CHECK (sender IN ('user', 'assistant')),
    CONSTRAINT search_message_session_fk FOREIGN KEY (session_id)
        REFERENCES document_search_sessions (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Which documents the assistant returned for an answer, in rank order,
-- and what matched (file name, OCR text, or AI label).
CREATE TABLE document_search_results (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    message_id  INT UNSIGNED NOT NULL,
    document_id INT UNSIGNED NOT NULL,
    result_rank TINYINT UNSIGNED NOT NULL,
    match_score DECIMAL(8,3) NULL,
    matched_on  VARCHAR(20)  NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY search_result_unique (message_id, document_id),
    KEY search_result_document_idx (document_id),
    CONSTRAINT search_result_matched_chk CHECK (matched_on IN ('filename', 'ocr_text', 'image_label', 'text_label')),
    CONSTRAINT search_result_message_fk FOREIGN KEY (message_id)
        REFERENCES document_search_messages (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT search_result_document_fk FOREIGN KEY (document_id)
        REFERENCES document_archive (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who viewed, downloaded, or changed an archive document (access control audit).
CREATE TABLE document_access_logs (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    document_id INT UNSIGNED NOT NULL,
    user_id     INT UNSIGNED NULL,
    action      VARCHAR(20)  NOT NULL,
    ip_address  VARCHAR(45)  NULL,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY document_access_document_idx (document_id, created_at),
    KEY document_access_user_idx (user_id),
    CONSTRAINT document_access_action_chk CHECK (action IN ('upload', 'view', 'download', 'update', 'delete', 'verify')),
    CONSTRAINT document_access_document_fk FOREIGN KEY (document_id)
        REFERENCES document_archive (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT document_access_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Kinds of student requirements (birth certificate, valid ID, ...).
CREATE TABLE requirement_types (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    code        VARCHAR(40)  NOT NULL,
    name        VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    is_required TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY requirement_type_code_unique (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Learning center Document Management System: each student's requirements
-- and records. A row with no file yet means "missing".
-- Uploads go through the same OCR + AI pipeline, so student files can be
-- found by their text too (ocr_text, ai_label, FULLTEXT index).
CREATE TABLE student_documents (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    training_center_id  INT UNSIGNED NOT NULL,
    student_id          INT UNSIGNED NOT NULL,
    requirement_type_id INT UNSIGNED NOT NULL,
    original_name       VARCHAR(255) NULL,
    storage_key         CHAR(40)     NULL,
    mime_type           VARCHAR(100) NULL,
    file_size           INT UNSIGNED NULL,
    file_hash           CHAR(64)     NULL,
    ocr_text            MEDIUMTEXT   NULL,
    ai_label            VARCHAR(100) NULL,
    ai_confidence       DECIMAL(5,2) NULL,
    status              VARCHAR(20)  NOT NULL DEFAULT 'missing',
    remarks             VARCHAR(500) NULL,
    uploaded_by         INT UNSIGNED NULL,
    verified_by         INT UNSIGNED NULL,
    verified_at         DATETIME     NULL,
    created_at          TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY student_document_storage_key_unique (storage_key),
    UNIQUE KEY student_requirement_unique (student_id, requirement_type_id),
    KEY student_document_center_idx (training_center_id, status),
    KEY student_document_type_idx (requirement_type_id),
    KEY student_document_uploaded_by_idx (uploaded_by),
    KEY student_document_verified_by_idx (verified_by),
    KEY student_document_ai_label_idx (ai_label),
    FULLTEXT KEY student_document_search_ft (original_name, ocr_text),
    CONSTRAINT student_document_status_chk CHECK (status IN ('missing', 'submitted', 'verified', 'rejected')),
    CONSTRAINT student_document_file_chk CHECK (status = 'missing' OR storage_key IS NOT NULL),
    CONSTRAINT student_document_confidence_chk CHECK (ai_confidence IS NULL OR (ai_confidence >= 0 AND ai_confidence <= 100)),
    CONSTRAINT student_document_ai_label_fk FOREIGN KEY (ai_label)
        REFERENCES document_categories (code) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT student_document_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT student_document_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT student_document_type_fk FOREIGN KEY (requirement_type_id)
        REFERENCES requirement_types (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT student_document_uploaded_by_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT student_document_verified_by_fk FOREIGN KEY (verified_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Announcements from a learning center to its students
-- (batch_id set = only that batch; NULL = the whole center).
CREATE TABLE announcements (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    training_center_id INT UNSIGNED NOT NULL,
    batch_id           INT UNSIGNED NULL,
    posted_by          INT UNSIGNED NULL,
    title              VARCHAR(150) NOT NULL,
    body               TEXT         NOT NULL,
    is_published       TINYINT(1)   NOT NULL DEFAULT 1,
    published_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY announcement_center_idx (training_center_id, published_at),
    KEY announcement_batch_idx (batch_id),
    KEY announcement_posted_by_idx (posted_by),
    CONSTRAINT announcement_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT announcement_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT announcement_posted_by_fk FOREIGN KEY (posted_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  4. INVENTORY, SUPPLY REQUESTS AND DISTRIBUTION
-- =====================================================================

-- Items held by the main office (the backend's "products").
CREATE TABLE products (
    id            INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    product_name  VARCHAR(100)  NOT NULL,
    description   TEXT          NULL,
    category      VARCHAR(30)   NOT NULL DEFAULT 'supply',
    unit          VARCHAR(20)   NOT NULL DEFAULT 'pc',
    price         DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    quantity      INT           NOT NULL DEFAULT 0,
    reorder_level INT           NOT NULL DEFAULT 0,
    image_path    VARCHAR(255)  NULL,
    created_by    INT UNSIGNED  NULL,
    created_at    TIMESTAMP     NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY product_name_unique (product_name),
    KEY product_category_idx (category),
    KEY product_created_by_idx (created_by),
    CONSTRAINT product_category_chk CHECK (category IN ('supply', 'equipment', 'tool', 'ppe', 'consumable')),
    CONSTRAINT product_quantity_chk CHECK (quantity >= 0),
    CONSTRAINT product_price_chk CHECK (price >= 0),
    CONSTRAINT product_created_by_fk FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Delivery personnel a learning center can assign to pick up / deliver items.
CREATE TABLE delivery_personnel (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    training_center_id INT UNSIGNED NOT NULL,
    full_name          VARCHAR(150) NOT NULL,
    contact_number     VARCHAR(30)  NULL,
    vehicle            VARCHAR(100) NULL,
    plate_number       VARCHAR(20)  NULL,
    is_active          TINYINT(1)   NOT NULL DEFAULT 1,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY delivery_personnel_center_idx (training_center_id, is_active),
    CONSTRAINT delivery_personnel_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A learning center's request for supplies / equipment from the main office.
CREATE TABLE inventory_requests (
    id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_code          VARCHAR(20)  NOT NULL,
    training_center_id    INT UNSIGNED NOT NULL,
    requested_by          INT UNSIGNED NULL,
    delivery_personnel_id INT UNSIGNED NULL,
    purpose               TEXT         NOT NULL,
    status                VARCHAR(20)  NOT NULL DEFAULT 'pending',
    admin_remarks         TEXT         NULL,
    reviewed_by           INT UNSIGNED NULL,
    reviewed_at           DATETIME     NULL,
    released_at           DATETIME     NULL,
    received_at           DATETIME     NULL,
    created_at            TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY inventory_request_code_unique (request_code),
    KEY inventory_request_queue_idx (status, created_at),
    KEY inventory_request_center_idx (training_center_id, status),
    KEY inventory_request_requested_by_idx (requested_by),
    KEY inventory_request_delivery_idx (delivery_personnel_id),
    KEY inventory_request_reviewed_by_idx (reviewed_by),
    CONSTRAINT inventory_request_status_chk CHECK (status IN ('pending', 'in_review', 'approved', 'rejected', 'released', 'received', 'cancelled')),
    CONSTRAINT inventory_request_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT inventory_request_requested_by_fk FOREIGN KEY (requested_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT inventory_request_delivery_fk FOREIGN KEY (delivery_personnel_id)
        REFERENCES delivery_personnel (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT inventory_request_reviewed_by_fk FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The items (and quantities) inside an inventory request.
CREATE TABLE inventory_request_items (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id         INT UNSIGNED NOT NULL,
    product_id         INT UNSIGNED NOT NULL,
    quantity_requested INT UNSIGNED NOT NULL,
    quantity_approved  INT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY request_product_unique (request_id, product_id),
    KEY request_item_product_idx (product_id),
    CONSTRAINT request_item_qty_chk CHECK (quantity_requested > 0),
    CONSTRAINT request_item_approved_chk CHECK (quantity_approved IS NULL OR quantity_approved <= quantity_requested),
    CONSTRAINT request_item_request_fk FOREIGN KEY (request_id)
        REFERENCES inventory_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT request_item_product_fk FOREIGN KEY (product_id)
        REFERENCES products (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every stock change: restocks, releases to centers, adjustments.
-- Released items point to the request they were sent for (distribution record).
CREATE TABLE stock_movements (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id         INT UNSIGNED NOT NULL,
    movement_type      VARCHAR(20)  NOT NULL,
    quantity_change    INT          NOT NULL,
    request_id         INT UNSIGNED NULL,
    training_center_id INT UNSIGNED NULL,
    remarks            VARCHAR(255) NULL,
    recorded_by        INT UNSIGNED NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY stock_movement_product_idx (product_id, created_at),
    KEY stock_movement_request_idx (request_id),
    KEY stock_movement_center_idx (training_center_id),
    KEY stock_movement_recorded_by_idx (recorded_by),
    CONSTRAINT stock_movement_type_chk CHECK (movement_type IN ('restock', 'release', 'return', 'adjustment')),
    CONSTRAINT stock_movement_nonzero_chk CHECK (quantity_change <> 0),
    CONSTRAINT stock_movement_product_fk FOREIGN KEY (product_id)
        REFERENCES products (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT stock_movement_request_fk FOREIGN KEY (request_id)
        REFERENCES inventory_requests (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT stock_movement_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT stock_movement_recorded_by_fk FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  5. ALLOWANCE REQUESTS
-- =====================================================================

-- A learning center's allowance request for one batch.
CREATE TABLE allowance_requests (
    id                 INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    request_code       VARCHAR(20)   NOT NULL,
    training_center_id INT UNSIGNED  NOT NULL,
    batch_id           INT UNSIGNED  NOT NULL,
    requested_by       INT UNSIGNED  NULL,
    amount_per_student DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    status             VARCHAR(20)   NOT NULL DEFAULT 'pending',
    notes              TEXT          NULL,
    admin_remarks      TEXT          NULL,
    reviewed_by        INT UNSIGNED  NULL,
    reviewed_at        DATETIME      NULL,
    released_at        DATETIME      NULL,
    created_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY allowance_request_code_unique (request_code),
    KEY allowance_request_queue_idx (status, created_at),
    KEY allowance_request_center_idx (training_center_id, status),
    KEY allowance_request_batch_idx (batch_id),
    KEY allowance_request_requested_by_idx (requested_by),
    KEY allowance_request_reviewed_by_idx (reviewed_by),
    CONSTRAINT allowance_request_status_chk CHECK (status IN ('pending', 'budget_check', 'for_approval', 'approved', 'rejected', 'released')),
    CONSTRAINT allowance_request_amount_chk CHECK (amount_per_student >= 0),
    CONSTRAINT allowance_request_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT allowance_request_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT allowance_request_requested_by_fk FOREIGN KEY (requested_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT allowance_request_reviewed_by_fk FOREIGN KEY (reviewed_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Students covered by an allowance request: the admin's checklist
-- (bank details are copied here so the record does not change later).
CREATE TABLE allowance_request_students (
    id                   INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    allowance_request_id INT UNSIGNED  NOT NULL,
    student_id           INT UNSIGNED  NOT NULL,
    amount               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    bank_account_number  VARCHAR(40)   NULL,
    bank_account_holder  VARCHAR(150)  NULL,
    is_checked           TINYINT(1)    NOT NULL DEFAULT 0,
    checked_by           INT UNSIGNED  NULL,
    checked_at           DATETIME      NULL,
    released_at          DATETIME      NULL,
    PRIMARY KEY (id),
    UNIQUE KEY allowance_student_unique (allowance_request_id, student_id),
    KEY allowance_student_student_idx (student_id),
    KEY allowance_student_checked_by_idx (checked_by),
    CONSTRAINT allowance_student_amount_chk CHECK (amount >= 0),
    CONSTRAINT allowance_student_request_fk FOREIGN KEY (allowance_request_id)
        REFERENCES allowance_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT allowance_student_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT allowance_student_checked_by_fk FOREIGN KEY (checked_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  6. NOTIFICATIONS, ACTIVITY AND BLOCKCHAIN LEDGER
-- =====================================================================

-- Status updates sent to a user (request approved, document verified, ...).
CREATE TABLE notifications (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id     INT UNSIGNED NOT NULL,
    title       VARCHAR(150) NOT NULL,
    message     VARCHAR(500) NOT NULL,
    entity_type VARCHAR(40)  NULL,
    entity_id   INT UNSIGNED NULL,
    is_read     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY notification_user_idx (user_id, is_read, created_at),
    CONSTRAINT notification_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Who did what, when (dashboard "Recent activity" and audit trail).
CREATE TABLE activity_logs (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id            INT UNSIGNED NULL,
    training_center_id INT UNSIGNED NULL,
    action             VARCHAR(50)  NOT NULL,
    entity_type        VARCHAR(40)  NOT NULL,
    entity_id          INT UNSIGNED NULL,
    description        VARCHAR(500) NULL,
    ip_address         VARCHAR(45)  NULL,
    created_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY activity_user_idx (user_id),
    KEY activity_center_idx (training_center_id, created_at),
    KEY activity_entity_idx (entity_type, entity_id),
    CONSTRAINT activity_user_fk FOREIGN KEY (user_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT activity_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
--  BLOCKCHAIN
--  A private, tamper-evident hash chain kept in the database.
--  Each block = one important transaction (document upload, request
--  approval / release, allowance release). It stores:
--    data_hash     SHA-256 of the record's key data (for documents: the
--                  file's SHA-256, same as document_archive.file_hash)
--    previous_hash the block_hash of the block before it
--    block_hash    SHA-256 of (block_index | entity_type | entity_id |
--                  action | data_hash | previous_hash)
--  Editing any old record changes its hash and breaks every block after
--  it, so tampering is detectable. tx_hash / network can hold an on-chain
--  transaction id if blocks are later anchored to a public blockchain.
-- ---------------------------------------------------------------------
CREATE TABLE blockchain_ledger (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    block_index       INT UNSIGNED NOT NULL,
    previous_block_id INT UNSIGNED NULL,
    entity_type       VARCHAR(40)  NOT NULL,
    entity_id         INT UNSIGNED NULL,
    action            VARCHAR(50)  NOT NULL,
    data_hash         CHAR(64)     NOT NULL,
    previous_hash     CHAR(64)     NOT NULL,
    block_hash        CHAR(64)     NOT NULL,
    network           VARCHAR(40)  NULL,
    tx_hash           VARCHAR(100) NULL,
    created_by        INT UNSIGNED NULL,
    created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY ledger_block_index_unique (block_index),
    UNIQUE KEY ledger_block_hash_unique (block_hash),
    UNIQUE KEY ledger_previous_block_unique (previous_block_id),
    KEY ledger_entity_idx (entity_type, entity_id),
    KEY ledger_data_hash_idx (data_hash),
    KEY ledger_created_by_idx (created_by),
    CONSTRAINT ledger_entity_type_chk CHECK (entity_type IN ('system', 'document', 'student_document', 'inventory_request', 'allowance_request', 'stock_movement')),
    CONSTRAINT ledger_previous_block_fk FOREIGN KEY (previous_block_id)
        REFERENCES blockchain_ledger (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT ledger_created_by_fk FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Results of checking a record against the chain ("Verify document"):
-- the hash recomputed now vs. the one stored in the block.
CREATE TABLE blockchain_verifications (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ledger_id       INT UNSIGNED NOT NULL,
    recomputed_hash CHAR(64)     NOT NULL,
    is_valid        TINYINT(1)   NOT NULL,
    remarks         VARCHAR(255) NULL,
    verified_by     INT UNSIGNED NULL,
    verified_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY verification_ledger_idx (ledger_id, verified_at),
    KEY verification_user_idx (verified_by),
    CONSTRAINT verification_ledger_fk FOREIGN KEY (ledger_id)
        REFERENCES blockchain_ledger (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT verification_user_fk FOREIGN KEY (verified_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- =====================================================================
--  7. HANDY VIEWS
-- =====================================================================

-- Programs with how many batches and students they have (Program Lists page).
CREATE OR REPLACE VIEW v_program_summary AS
SELECT
    c.id,
    c.training_center_id,
    c.course_code,
    c.course_name,
    c.qualification,
    c.status,
    COUNT(DISTINCT b.id)          AS batch_count,
    COUNT(DISTINCT bs.student_id) AS student_count
FROM courses c
LEFT JOIN batches b         ON b.course_id = c.id
LEFT JOIN batch_students bs ON bs.batch_id = b.id AND bs.status = 'active'
GROUP BY c.id, c.training_center_id, c.course_code, c.course_name, c.qualification, c.status;

-- Inventory requests with center name and item totals (admin request list).
CREATE OR REPLACE VIEW v_inventory_request_summary AS
SELECT
    r.id,
    r.request_code,
    r.training_center_id,
    tc.name                    AS center_name,
    r.status,
    COUNT(i.id)                AS item_count,
    SUM(i.quantity_requested)  AS total_quantity,
    r.created_at
FROM inventory_requests r
JOIN training_centers tc ON tc.id = r.training_center_id
LEFT JOIN inventory_request_items i ON i.request_id = r.id
GROUP BY r.id, r.request_code, r.training_center_id, tc.name, r.status, r.created_at;

-- Allowance requests with center, batch and checklist progress (Allowance Tracker).
CREATE OR REPLACE VIEW v_allowance_request_summary AS
SELECT
    a.id,
    a.request_code,
    a.training_center_id,
    tc.name                          AS center_name,
    b.batch_name,
    a.status,
    COUNT(s.id)                      AS student_count,
    COALESCE(SUM(s.is_checked), 0)   AS checked_count,
    COALESCE(SUM(s.amount), 0)       AS total_amount,
    a.created_at
FROM allowance_requests a
JOIN training_centers tc ON tc.id = a.training_center_id
JOIN batches b           ON b.id = a.batch_id
LEFT JOIN allowance_request_students s ON s.allowance_request_id = a.id
GROUP BY a.id, a.request_code, a.training_center_id, tc.name, b.batch_name, a.status, a.created_at;


-- =====================================================================
--  8. DEMO DATA
--  Password for every account below: Tesda@2026
-- =====================================================================

INSERT INTO training_centers (id, center_code, name, center_type, region, province, city, address, contact_email, contact_phone) VALUES
(1, 'TESDA-MAIN',      'TESDA Region IV-B Main Office',               'main_office',     'MIMAROPA (Region IV-B)', 'Oriental Mindoro', 'Calapan City',        'Calapan City, Oriental Mindoro', 'region4b@tesda.gov.ph',  '(043) 288-0000'),
(2, 'LC-ORMIN',        'Provincial Training Center - Oriental Mindoro', 'learning_center', 'MIMAROPA (Region IV-B)', 'Oriental Mindoro', 'Calapan City',        'Calapan City, Oriental Mindoro', 'ormin@tesda.gov.ph',     '(043) 288-1111'),
(3, 'LC-PALAWAN',      'Provincial Training Center - Palawan',          'learning_center', 'MIMAROPA (Region IV-B)', 'Palawan',          'Puerto Princesa City', 'Puerto Princesa City, Palawan',  'palawan@tesda.gov.ph',   '(048) 433-2222'),
(4, 'LC-MARINDUQUE',   'Provincial Training Center - Marinduque',       'learning_center', 'MIMAROPA (Region IV-B)', 'Marinduque',       'Boac',                'Boac, Marinduque',               'marinduque@tesda.gov.ph','(042) 332-3333'),
(5, 'LC-ROMBLON',      'Provincial Training Center - Romblon',          'learning_center', 'MIMAROPA (Region IV-B)', 'Romblon',          'Odiongan',            'Odiongan, Romblon',              'romblon@tesda.gov.ph',   '(042) 567-4444');

-- Password hash below = 'Tesda@2026'
INSERT INTO users (id, username, full_name, email, password, role, training_center_id, contact_number) VALUES
(1,  'admin',          'Main Office Administrator', 'admin@gmail.com',          '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'admin',        NULL, '09170000001'),
(2,  'ormin.admin',    'Maria Santos',              'ormin.admin@gmail.com',    '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'center_admin', 2,    '09170000002'),
(3,  'palawan.admin',  'Jose Ramirez',              'palawan.admin@gmail.com',  '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'center_admin', 3,    '09170000003'),
(4,  'teacher.reyes',  'Ana Reyes',                 'teacher.reyes@gmail.com',  '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'instructor',   2,    '09170000004'),
(5,  'teacher.cruz',   'Ramon Cruz',                'teacher.cruz@gmail.com',   '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'instructor',   2,    '09170000005'),
(6,  'teacher.lim',    'Grace Lim',                 'teacher.lim@gmail.com',    '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'instructor',   3,    '09170000006'),
(7,  'juan.delacruz',  'Juan Dela Cruz',            'juan.delacruz@gmail.com',  '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      2,    '09170000007'),
(8,  'liza.garcia',    'Liza Garcia',               'liza.garcia@gmail.com',    '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      2,    '09170000008'),
(9,  'mark.villanueva','Mark Villanueva',           'mark.villanueva@gmail.com','$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      2,    '09170000009'),
(10, 'rose.mendoza',   'Rose Mendoza',              'rose.mendoza@gmail.com',   '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      2,    '09170000010'),
(11, 'paolo.bautista', 'Paolo Bautista',            'paolo.bautista@gmail.com', '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      3,    '09170000011'),
(12, 'carla.aquino',   'Carla Aquino',              'carla.aquino@gmail.com',   '$2y$10$xR5CEwPiugeiBslb67nv6.GTCBwjjDOQJ.fcqSPItpxomKDffZeHy', 'student',      3,    '09170000012');

INSERT INTO student_profiles (user_id, student_number, first_name, middle_name, last_name, birthdate, sex, address, guardian_name, guardian_contact, bank_name, bank_account_number, bank_account_holder, created_by) VALUES
(7,  '2026-ORM-0001', 'Juan',  'Santos',  'Dela Cruz',  '2003-04-12', 'male',   'Calapan City, Oriental Mindoro',  'Pedro Dela Cruz',  '09181000007', 'LandBank', '0123-4567-0001', 'Juan S. Dela Cruz',  4),
(8,  '2026-ORM-0002', 'Liza',  'Ramos',   'Garcia',     '2002-09-30', 'female', 'Naujan, Oriental Mindoro',        'Elena Garcia',     '09181000008', 'LandBank', '0123-4567-0002', 'Liza R. Garcia',     4),
(9,  '2026-ORM-0003', 'Mark',  NULL,      'Villanueva', '2004-01-18', 'male',   'Baco, Oriental Mindoro',          'Rico Villanueva',  '09181000009', 'LandBank', '0123-4567-0003', 'Mark Villanueva',    4),
(10, '2026-ORM-0004', 'Rose',  'Tan',     'Mendoza',    '2001-11-05', 'female', 'Victoria, Oriental Mindoro',      'Luz Mendoza',      '09181000010', 'LandBank', '0123-4567-0004', 'Rose T. Mendoza',    5),
(11, '2026-PAL-0001', 'Paolo', 'Reyes',   'Bautista',   '2003-07-22', 'male',   'Puerto Princesa City, Palawan',   'Nora Bautista',    '09181000011', 'LandBank', '0456-7890-0001', 'Paolo R. Bautista',  6),
(12, '2026-PAL-0002', 'Carla', NULL,      'Aquino',     '2002-03-14', 'female', 'Roxas, Palawan',                  'Ben Aquino',       '09181000012', 'LandBank', '0456-7890-0002', 'Carla Aquino',       6);

INSERT INTO courses (id, training_center_id, course_code, course_name, qualification, description, class_days, class_schedule, duration_hours, capacity, status) VALUES
(1, 2, 'CSS-NCII',  'Computer Systems Servicing',      'NC II', 'Install, configure, and maintain computer systems and networks.', 'Monday, Wednesday, Friday', '8:00 AM - 12:00 PM, ICT Room 1',      280, 25, 'open'),
(2, 2, 'SMAW-NCII', 'Shielded Metal Arc Welding',      'NC II', 'Weld carbon steel plates and pipes using SMAW.',                  'Tuesday, Thursday',          '1:00 PM - 5:00 PM, Welding Shop',      268, 20, 'open'),
(3, 2, 'COOK-NCII', 'Cookery',                         'NC II', 'Prepare and cook hot and cold meals in a commercial kitchen.',    'Monday to Friday',           '8:00 AM - 12:00 PM, Training Kitchen', 316, 24, 'open'),
(4, 3, 'BPP-NCII',  'Bread and Pastry Production',     'NC II', 'Prepare bakery and pastry products using standard methods.',      'Monday, Tuesday, Thursday',  '8:00 AM - 12:00 PM, Baking Lab',       141, 24, 'open'),
(5, 3, 'EIM-NCII',  'Electrical Installation and Maintenance', 'NC II', 'Install and maintain electrical wiring in buildings.',     'Wednesday, Friday',          '1:00 PM - 5:00 PM, Electrical Shop',   196, 20, 'open');

INSERT INTO course_materials (course_id, uploaded_by, title, description, resource_url) VALUES
(1, 4, 'PC Assembly Guide',        'Step-by-step hardware assembly handout.',  'https://example.com/materials/css-pc-assembly.pdf'),
(2, 5, 'Welding Safety Checklist', 'PPE and workshop safety before each session.', 'https://example.com/materials/smaw-safety.pdf');

INSERT INTO batches (id, course_id, instructor_id, batch_number, batch_name, start_date, end_date, capacity, status, created_by) VALUES
(1, 1, 4, 1, 'CSS Batch 2026-01',  '2026-08-03', '2026-11-27', 25, 'ongoing',   4),
(2, 1, 4, 2, 'CSS Batch 2026-02',  '2026-12-07', '2027-03-26', 25, 'upcoming',  4),
(3, 2, 5, 1, 'SMAW Batch 2026-01', '2026-09-01', '2026-12-18', 20, 'ongoing',   5),
(4, 4, 6, 1, 'BPP Batch 2026-01',  '2026-08-10', '2026-10-30', 24, 'completed', 6);

INSERT INTO batch_students (batch_id, student_id, status, assigned_by) VALUES
(1, 7,  'active',    4),
(1, 8,  'active',    4),
(1, 9,  'active',    4),
(3, 10, 'active',    5),
(4, 11, 'completed', 6),
(4, 12, 'completed', 6);

INSERT INTO enrollments (student_id, course_id, instructor_id, status) VALUES
(7,  1, 4, 'enrolled'),
(8,  1, 4, 'enrolled'),
(9,  1, 4, 'enrolled'),
(10, 2, 5, 'enrolled'),
(11, 4, 6, 'completed'),
(12, 4, 6, 'completed');

INSERT INTO requirement_types (id, code, name, description, is_required, sort_order) VALUES
(1, 'PSA-BC',    'PSA Birth Certificate',     'Photocopy of PSA-issued birth certificate.', 1, 1),
(2, 'VALID-ID',  'Valid Government ID',       'Any government-issued ID.',                  1, 2),
(3, 'TOR-F137',  'Form 137 / TOR',            'Latest school records.',                     1, 3),
(4, 'PHOTO-2X2', '2x2 ID Photo',              'White background, taken within 6 months.',   1, 4),
(5, 'BRGY-CLR',  'Barangay Clearance',        'Issued within the last 6 months.',           0, 5),
(6, 'BANK-PROOF','Proof of Bank Account',     'Needed for allowance release.',              0, 6);

-- The 16 labels both AI models predict (from Back-End/ai/models/*_metadata.json).
INSERT INTO document_categories (code, display_name, description) VALUES
('advertisement',          'Advertisement',          'Flyers, posters and promotional material'),
('budget',                 'Budget',                 'Budget plans and financial breakdowns'),
('email',                  'Email',                  'Printed email correspondence'),
('file_folder',            'File Folder',            'Folder covers and file labels'),
('form',                   'Form',                   'Filled-in or blank forms'),
('handwritten',            'Handwritten',            'Handwritten notes and documents'),
('invoice',                'Invoice',                'Invoices, receipts and billing statements'),
('letter',                 'Letter',                 'Official and personal letters'),
('memo',                   'Memo',                   'Memoranda and internal notices'),
('news_article',           'News Article',           'Newspaper and magazine articles'),
('presentation',           'Presentation',           'Slides and presentation printouts'),
('questionnaire',          'Questionnaire',          'Surveys and questionnaires'),
('resume',                 'Resume',                 'Resumes and CVs'),
('scientific_publication', 'Scientific Publication', 'Published papers and journals'),
('scientific_report',      'Scientific Report',      'Technical and research reports'),
('specification',          'Specification',          'Technical specifications and data sheets');

-- AI models used by Back-End/ai/inference.py (accuracy from the metadata files).
INSERT INTO ai_models (model_code, model_type, name, architecture, file_path, num_classes, accuracy_percent, evaluated_on, notes) VALUES
('custom_cnn',                'image_classifier', 'Document Image Classifier', 'DocumentCNN (custom CNN, 128x128 input)', 'ai/models/custom_cnn_best.pth',             16, 71.07, 992, 'Returns a confidence and top predictions per page.'),
('text_classifier_prototype', 'text_classifier',  'OCR Text Classifier',       'TF-IDF + LinearSVC',                      'ai/models/text_classifier_prototype.joblib', 16, 58.90, 236, 'Needs at least 3 words. No calibrated confidence score.'),
('tesseract',                 'ocr_engine',       'Tesseract OCR',             'Tesseract + PyMuPDF for PDFs',            NULL,                                         NULL, NULL, NULL, 'PDFs up to 20 pages; embedded PDF text is used when available.');

-- Requirement checklist for two students (no files uploaded yet = missing).
INSERT INTO student_documents (training_center_id, student_id, requirement_type_id, status, remarks) VALUES
(2, 7, 1, 'missing', NULL),
(2, 7, 2, 'missing', NULL),
(2, 7, 4, 'missing', NULL),
(2, 8, 1, 'missing', NULL),
(2, 8, 6, 'missing', 'Needed before allowance release.');

INSERT INTO announcements (training_center_id, batch_id, posted_by, title, body) VALUES
(2, NULL, 2, 'Enrollment for CSS Batch 2026-02 is open', 'Submit your requirements at the registrar office until November 30, 2026.'),
(2, 1,    4, 'Assessment schedule',                     'The CSS NC II assessment for Batch 2026-01 is on November 25, 2026, 8:00 AM.');

INSERT INTO products (id, product_name, description, category, unit, price, quantity, reorder_level, created_by) VALUES
(1, 'Welding gloves',       'Heat-resistant gloves for supervised welding practice.', 'ppe',        'pair', 450.00,  18, 10, 1),
(2, 'Safety goggles',       'Protective eyewear for workshop activities.',            'ppe',        'pc',   180.00,  32, 10, 1),
(3, 'Digital multimeter',   'Basic electrical testing tool.',                         'tool',       'pc',   850.00,  12,  5, 1),
(4, 'First aid kit',        'Restocked first aid kit for a training room.',           'supply',     'kit',  650.00,   8,  5, 1),
(5, 'Welding machine (MIG)','MIG (Metal Inert Gas / GMAW) welding machine.',          'equipment',  'unit', 28500.00, 4,  1, 1),
(6, 'Laptop',               'Core i5 laptop for ICT training.',                       'equipment',  'unit', 38000.00, 10, 2, 1);

INSERT INTO delivery_personnel (id, training_center_id, full_name, contact_number, vehicle, plate_number) VALUES
(1, 2, 'Carlos Medina',  '09191000001', 'L300 Van',       'ABC 1234'),
(2, 2, 'Ben Torres',     '09191000002', 'Motorcycle',     'MC 5678'),
(3, 3, 'Ely Navarro',    '09191000003', 'Pickup Truck',   'XYZ 9012');

INSERT INTO inventory_requests (id, request_code, training_center_id, requested_by, delivery_personnel_id, purpose, status, admin_remarks, reviewed_by, reviewed_at, released_at, received_at) VALUES
(1, 'REQ-0001', 2, 2, 1,    'Safety kits for the new SMAW batch.',   'pending',  NULL,                      NULL, NULL,                  NULL,                  NULL),
(2, 'REQ-0002', 2, 2, 2,    'Replacement goggles and first aid kit.','received', 'Approved in full.',        1,    '2026-10-05 09:30:00', '2026-10-06 08:00:00', '2026-10-06 15:20:00'),
(3, 'REQ-0003', 3, 3, 3,    'Multimeters for EIM training.',         'in_review','Checking available stock.', 1,    '2026-11-15 10:00:00', NULL,                  NULL);

INSERT INTO inventory_request_items (request_id, product_id, quantity_requested, quantity_approved) VALUES
(1, 1, 4, NULL),
(1, 2, 4, NULL),
(2, 2, 6, 6),
(2, 4, 1, 1),
(3, 3, 5, NULL);

INSERT INTO stock_movements (product_id, movement_type, quantity_change, request_id, training_center_id, remarks, recorded_by) VALUES
(1, 'restock', 20,  NULL, NULL, 'Initial stock',              1),
(2, 'restock', 38,  NULL, NULL, 'Initial stock',              1),
(4, 'restock', 9,   NULL, NULL, 'Initial stock',              1),
(2, 'release', -6,  2,    2,    'Released for REQ-0002',      1),
(4, 'release', -1,  2,    2,    'Released for REQ-0002',      1);

INSERT INTO allowance_requests (id, request_code, training_center_id, batch_id, requested_by, amount_per_student, status, notes, admin_remarks, reviewed_by, reviewed_at, released_at) VALUES
(1, 'ALW-0001', 2, 1, 2, 3000.00, 'pending',  'Training allowance for CSS Batch 2026-01.', NULL,                       NULL, NULL,                  NULL),
(2, 'ALW-0002', 3, 4, 3, 2500.00, 'released', 'Allowance for completed BPP batch.',        'Released via LandBank.',   1,    '2026-11-03 14:00:00', '2026-11-05 10:00:00');

INSERT INTO allowance_request_students (allowance_request_id, student_id, amount, bank_account_number, bank_account_holder, is_checked, checked_by, checked_at, released_at) VALUES
(1, 7,  3000.00, '0123-4567-0001', 'Juan S. Dela Cruz', 0, NULL, NULL, NULL),
(1, 8,  3000.00, '0123-4567-0002', 'Liza R. Garcia',    0, NULL, NULL, NULL),
(1, 9,  3000.00, '0123-4567-0003', 'Mark Villanueva',   0, NULL, NULL, NULL),
(2, 11, 2500.00, '0456-7890-0001', 'Paolo R. Bautista', 1, 1, '2026-11-03 13:40:00', '2026-11-05 10:00:00'),
(2, 12, 2500.00, '0456-7890-0002', 'Carla Aquino',      1, 1, '2026-11-03 13:42:00', '2026-11-05 10:00:00');

INSERT INTO notifications (user_id, title, message, entity_type, entity_id, is_read) VALUES
(2,  'Inventory request received',  'REQ-0002 was approved and delivered.',          'inventory_request', 2, 1),
(3,  'Inventory request in review', 'REQ-0003 is being reviewed by the main office.', 'inventory_request', 3, 0),
(3,  'Allowance released',          'ALW-0002 allowance was released.',               'allowance_request', 2, 0),
(11, 'Allowance released',          'Your training allowance of PHP 2,500 was released.', 'allowance_request', 2, 0),
(1,  'New inventory request',       'Oriental Mindoro submitted REQ-0001.',           'inventory_request', 1, 0),
(1,  'New allowance request',       'Oriental Mindoro submitted ALW-0001.',           'allowance_request', 1, 0);

INSERT INTO activity_logs (user_id, training_center_id, action, entity_type, entity_id, description) VALUES
(2, 2, 'submitted', 'inventory_request', 1, 'Submitted REQ-0001: 4 welding gloves, 4 safety goggles'),
(1, 2, 'released',  'inventory_request', 2, 'Released REQ-0002 to Oriental Mindoro'),
(2, 2, 'submitted', 'allowance_request', 1, 'Requested allowance for CSS Batch 2026-01 (3 students)'),
(1, 3, 'released',  'allowance_request', 2, 'Released ALW-0002 for BPP Batch 2026-01'),
(4, 2, 'created',   'batch',             2, 'Created CSS Batch 2026-02');

-- Blockchain ledger: genesis block + two chained blocks.
-- block_hash = SHA-256(block_index | entity_type | entity_id | action | data_hash | previous_hash)
INSERT INTO blockchain_ledger (block_index, previous_block_id, entity_type, entity_id, action, data_hash, previous_hash, block_hash, created_by)
SELECT 0, NULL, 'system', NULL, 'genesis',
       SHA2('TESDA NEXUS genesis block', 256),
       REPEAT('0', 64),
       SHA2(CONCAT_WS('|', 0, 'system', '', 'genesis', SHA2('TESDA NEXUS genesis block', 256), REPEAT('0', 64)), 256),
       1;

INSERT INTO blockchain_ledger (block_index, previous_block_id, entity_type, entity_id, action, data_hash, previous_hash, block_hash, created_by)
SELECT 1, prev.id, 'inventory_request', 2, 'released',
       SHA2('REQ-0002|LC-ORMIN|goggles:6|first_aid_kit:1', 256),
       prev.block_hash,
       SHA2(CONCAT_WS('|', 1, 'inventory_request', 2, 'released', SHA2('REQ-0002|LC-ORMIN|goggles:6|first_aid_kit:1', 256), prev.block_hash), 256),
       1
FROM blockchain_ledger prev WHERE prev.block_index = 0;

INSERT INTO blockchain_ledger (block_index, previous_block_id, entity_type, entity_id, action, data_hash, previous_hash, block_hash, created_by)
SELECT 2, prev.id, 'allowance_request', 2, 'released',
       SHA2('ALW-0002|LC-PALAWAN|students:2|total:5000.00', 256),
       prev.block_hash,
       SHA2(CONCAT_WS('|', 2, 'allowance_request', 2, 'released', SHA2('ALW-0002|LC-PALAWAN|students:2|total:5000.00', 256), prev.block_hash), 256),
       1
FROM blockchain_ledger prev WHERE prev.block_index = 1;

-- Done. Quick check:
SELECT 'training_centers' AS table_name, COUNT(*) AS row_count FROM training_centers
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'courses', COUNT(*) FROM courses
UNION ALL SELECT 'batches', COUNT(*) FROM batches
UNION ALL SELECT 'batch_students', COUNT(*) FROM batch_students
UNION ALL SELECT 'products', COUNT(*) FROM products
UNION ALL SELECT 'inventory_requests', COUNT(*) FROM inventory_requests
UNION ALL SELECT 'allowance_requests', COUNT(*) FROM allowance_requests
UNION ALL SELECT 'document_categories', COUNT(*) FROM document_categories
UNION ALL SELECT 'ai_models', COUNT(*) FROM ai_models
UNION ALL SELECT 'blockchain_ledger', COUNT(*) FROM blockchain_ledger;
