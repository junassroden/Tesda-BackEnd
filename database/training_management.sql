-- =====================================================================
--  TESDA NEXUS — Training management upgrade
--
--  Adds, on top of tesda_nexus.sql:
--    * course duration and training mode
--    * batch schedules, lifecycle (suspend / extend / complete) and history
--    * training sessions and attendance (drives training progress)
--    * multi-copy student documents with a verification history
--    * the virtual classroom (one classroom per batch)
--
--  Safe to run more than once: every change checks information_schema
--  first, and existing rows are kept. Run it in the same database as
--  tesda_nexus.sql (HeidiSQL / phpMyAdmin / mysql client). MySQL 8.0.16+.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS tn_add_column;
DROP PROCEDURE IF EXISTS tn_drop_check;
DROP PROCEDURE IF EXISTS tn_drop_index;
DROP PROCEDURE IF EXISTS tn_add_index;

DELIMITER //
CREATE PROCEDURE tn_add_column(IN t VARCHAR(64), IN c VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND COLUMN_NAME = c) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD COLUMN `', c, '` ', def);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
CREATE PROCEDURE tn_drop_check(IN t VARCHAR(64), IN c VARCHAR(64))
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
               WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = t AND CONSTRAINT_NAME = c
                 AND CONSTRAINT_TYPE = 'CHECK') THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` DROP CHECK `', c, '`');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
CREATE PROCEDURE tn_drop_index(IN t VARCHAR(64), IN i VARCHAR(64))
BEGIN
    IF EXISTS (SELECT 1 FROM information_schema.STATISTICS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` DROP INDEX `', i, '`');
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
CREATE PROCEDURE tn_add_index(IN t VARCHAR(64), IN i VARCHAR(64), IN def TEXT)
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.STATISTICS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = t AND INDEX_NAME = i) THEN
        SET @sql = CONCAT('ALTER TABLE `', t, '` ADD ', def);
        PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
    END IF;
END //
DELIMITER ;


-- ---------------------------------------------------------------------
--  1. Courses: duration and training mode
-- ---------------------------------------------------------------------
CALL tn_add_column('courses', 'training_days', 'SMALLINT UNSIGNED NULL AFTER duration_hours');
CALL tn_add_column('courses', 'duration_value', 'SMALLINT UNSIGNED NULL AFTER training_days');
CALL tn_add_column('courses', 'duration_unit', "VARCHAR(10) NOT NULL DEFAULT 'weeks' AFTER duration_value");
CALL tn_add_column('courses', 'training_mode', "VARCHAR(20) NOT NULL DEFAULT 'face_to_face' AFTER duration_unit");
CALL tn_drop_check('courses', 'course_duration_unit_chk');
CALL tn_drop_check('courses', 'course_training_mode_chk');
ALTER TABLE courses
    ADD CONSTRAINT course_duration_unit_chk CHECK (duration_unit IN ('weeks', 'months')),
    ADD CONSTRAINT course_training_mode_chk CHECK (training_mode IN ('face_to_face', 'online', 'hybrid'));


-- ---------------------------------------------------------------------
--  2. Batches: schedule and lifecycle
--
--  status: upcoming -> ongoing <-> suspended -> completed  (or cancelled)
--  The lifecycle job (php lava batches:lifecycle, also run when batch
--  lists load) starts batches on their start date and completes a batch
--  only when its end date has passed AND its recorded training hours
--  meet the required hours AND requires_confirmation = 0. Anything else
--  stays active and shows as "Needs review" until an administrator acts.
-- ---------------------------------------------------------------------
CALL tn_add_column('batches', 'total_hours', 'INT UNSIGNED NULL AFTER end_date');
CALL tn_add_column('batches', 'training_days', 'SMALLINT UNSIGNED NULL AFTER total_hours');
CALL tn_add_column('batches', 'days_of_week', 'VARCHAR(30) NULL AFTER training_days');
CALL tn_add_column('batches', 'session_start', 'TIME NULL AFTER days_of_week');
CALL tn_add_column('batches', 'session_end', 'TIME NULL AFTER session_start');
CALL tn_add_column('batches', 'venue', 'VARCHAR(150) NULL AFTER session_end');
CALL tn_add_column('batches', 'session_frequency', 'VARCHAR(60) NULL AFTER venue');
CALL tn_add_column('batches', 'training_mode', 'VARCHAR(20) NULL AFTER session_frequency');
CALL tn_add_column('batches', 'planned_end_date', 'DATE NULL AFTER training_mode');
CALL tn_add_column('batches', 'requires_confirmation', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER planned_end_date');
CALL tn_add_column('batches', 'status_reason', 'VARCHAR(500) NULL AFTER status');
CALL tn_add_column('batches', 'suspended_at', 'DATETIME NULL AFTER status_reason');
CALL tn_add_column('batches', 'completed_at', 'DATETIME NULL AFTER suspended_at');
CALL tn_add_column('batches', 'completed_by', 'INT UNSIGNED NULL AFTER completed_at');
CALL tn_add_column('batches', 'completion_mode', 'VARCHAR(10) NULL AFTER completed_by');
CALL tn_drop_check('batches', 'batch_status_chk');
CALL tn_drop_check('batches', 'batch_training_mode_chk');
CALL tn_drop_check('batches', 'batch_session_time_chk');
ALTER TABLE batches
    ADD CONSTRAINT batch_status_chk CHECK (status IN ('upcoming', 'ongoing', 'suspended', 'completed', 'cancelled')),
    ADD CONSTRAINT batch_training_mode_chk CHECK (training_mode IS NULL OR training_mode IN ('face_to_face', 'online', 'hybrid')),
    ADD CONSTRAINT batch_session_time_chk CHECK (session_start IS NULL OR session_end IS NULL OR session_end > session_start);
CALL tn_add_index('batches', 'batch_completed_by_idx', 'KEY batch_completed_by_idx (completed_by)');
UPDATE batches SET planned_end_date = end_date WHERE planned_end_date IS NULL AND end_date IS NOT NULL;
-- A file belongs to either an item or a submission (checked in ClassroomModel;
-- MySQL does not allow CHECK constraints on cascading foreign key columns).
UPDATE batches SET completed_at = COALESCE(TIMESTAMP(end_date, '17:00:00'), created_at), completion_mode = 'manual'
 WHERE status = 'completed' AND completed_at IS NULL;


-- ---------------------------------------------------------------------
--  3. Batch membership history (who left, why, and where to)
-- ---------------------------------------------------------------------
CALL tn_add_column('batch_students', 'ended_at', 'DATETIME NULL AFTER assigned_at');
CALL tn_add_column('batch_students', 'ended_by', 'INT UNSIGNED NULL AFTER ended_at');
CALL tn_add_column('batch_students', 'end_reason', 'VARCHAR(255) NULL AFTER ended_by');
CALL tn_add_column('batch_students', 'transferred_to_batch_id', 'INT UNSIGNED NULL AFTER end_reason');
CALL tn_drop_check('batch_students', 'batch_student_status_chk');
ALTER TABLE batch_students
    ADD CONSTRAINT batch_student_status_chk CHECK (status IN ('active', 'completed', 'dropped', 'transferred'));


-- ---------------------------------------------------------------------
--  4. Training sessions and attendance
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS batch_sessions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id     INT UNSIGNED NOT NULL,
    session_date DATE         NOT NULL,
    start_time   TIME         NULL,
    end_time     TIME         NULL,
    hours        DECIMAL(4,2) NOT NULL DEFAULT 0,
    topic        VARCHAR(200) NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'held',
    recorded_by  INT UNSIGNED NULL,
    created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY batch_session_day_unique (batch_id, session_date),
    KEY batch_session_recorded_by_idx (recorded_by),
    CONSTRAINT batch_session_status_chk CHECK (status IN ('held', 'cancelled')),
    CONSTRAINT batch_session_hours_chk CHECK (hours >= 0 AND hours <= 24),
    CONSTRAINT batch_session_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT batch_session_recorded_by_fk FOREIGN KEY (recorded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS batch_attendance (
    id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    status     VARCHAR(20)  NOT NULL DEFAULT 'present',
    PRIMARY KEY (id),
    UNIQUE KEY batch_attendance_unique (session_id, student_id),
    KEY batch_attendance_student_idx (student_id),
    CONSTRAINT batch_attendance_status_chk CHECK (status IN ('present', 'late', 'absent', 'excused')),
    CONSTRAINT batch_attendance_session_fk FOREIGN KEY (session_id)
        REFERENCES batch_sessions (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT batch_attendance_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  5. Student documents: copies, conditions, and verification history
--
--  student_documents keeps one row per (center, student, requirement):
--  the requirement's status and the latest remarks. The files (copies)
--  live in student_document_files; replaced copies are kept with
--  is_current = 0. Every status change is written to
--  student_document_reviews. Documents belong to the student and the
--  center, not to a course, so a later enrollment reuses them.
-- ---------------------------------------------------------------------
CALL tn_add_column('requirement_types', 'required_copies', 'TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER is_required');
CALL tn_add_column('requirement_types', 'condition_code', 'VARCHAR(20) NULL AFTER required_copies');
CALL tn_add_column('requirement_types', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER condition_code');

-- The enrollment checklist. Existing birth certificate and ID rows are kept
-- (their ids are referenced) and updated; the old extras stay but are hidden.
UPDATE requirement_types SET name = 'Birth Certificate', description = 'PSA-issued birth certificate.',
       is_required = 1, required_copies = 4, condition_code = NULL, is_active = 1, sort_order = 3 WHERE code = 'PSA-BC';
UPDATE requirement_types SET name = 'Valid ID', description = 'Any government-issued ID, front and back.',
       is_required = 1, required_copies = 2, condition_code = NULL, is_active = 1, sort_order = 5 WHERE code = 'VALID-ID';
UPDATE requirement_types SET is_active = 0 WHERE code IN ('TOR-F137', 'PHOTO-2X2', 'BRGY-CLR', 'BANK-PROOF');
INSERT INTO requirement_types (code, name, description, is_required, required_copies, condition_code, is_active, sort_order)
SELECT * FROM (
    SELECT 'REG-FORM' AS code, 'Registration Form' AS name, 'Signed TESDA learner registration form.' AS description, 1 AS is_required, 1 AS required_copies, NULL AS condition_code, 1 AS is_active, 1 AS sort_order
    UNION ALL SELECT 'DIPLOMA', 'Diploma', 'Diploma from the highest level of schooling completed.', 1, 2, NULL, 1, 2
    UNION ALL SELECT 'MARRIAGE', 'Marriage Contract', 'PSA-issued marriage certificate. Required for married applicants.', 1, 1, 'married', 1, 4
    UNION ALL SELECT 'PASSPORT-PIC', 'Passport ID Picture', 'Passport-size photo, white background.', 1, 2, NULL, 1, 6
    UNION ALL SELECT 'PHOTO-1X1', '1x1 ID Picture', '1x1 photo, white background.', 1, 2, NULL, 1, 7
) AS t
WHERE NOT EXISTS (SELECT 1 FROM requirement_types r WHERE r.code = t.code);

-- Civil status decides whether the marriage contract is required.
CALL tn_add_column('student_profiles', 'civil_status', 'VARCHAR(20) NULL AFTER sex');
CALL tn_add_column('student_profiles', 'highest_education', 'VARCHAR(100) NULL AFTER civil_status');
CALL tn_drop_check('student_profiles', 'student_civil_status_chk');
ALTER TABLE student_profiles
    ADD CONSTRAINT student_civil_status_chk CHECK (civil_status IS NULL OR civil_status IN ('single', 'married', 'widowed', 'separated'));

-- New status values; files move to student_document_files.
CALL tn_drop_check('student_documents', 'student_document_status_chk');
CALL tn_drop_check('student_documents', 'student_document_file_chk');
UPDATE student_documents SET status = 'not_submitted' WHERE status = 'missing';
ALTER TABLE student_documents ALTER COLUMN status SET DEFAULT 'not_submitted';
ALTER TABLE student_documents
    ADD CONSTRAINT student_document_status_chk CHECK (status IN ('not_submitted', 'submitted', 'under_review', 'verified', 'rejected'));
CALL tn_add_column('student_documents', 'submitted_at', 'DATETIME NULL AFTER remarks');
CALL tn_add_index('student_documents', 'student_document_student_idx', 'KEY student_document_student_idx (student_id)');
CALL tn_drop_index('student_documents', 'student_requirement_unique');
CALL tn_add_index('student_documents', 'student_document_center_requirement_unique',
    'UNIQUE KEY student_document_center_requirement_unique (training_center_id, student_id, requirement_type_id)');

CREATE TABLE IF NOT EXISTS student_document_files (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_document_id INT UNSIGNED NOT NULL,
    original_name       VARCHAR(255) NOT NULL,
    storage_key         CHAR(36)     NOT NULL,
    mime_type           VARCHAR(100) NOT NULL,
    file_size           INT UNSIGNED NOT NULL,
    file_hash           CHAR(64)     NOT NULL,
    is_current          TINYINT(1)   NOT NULL DEFAULT 1,
    uploaded_by         INT UNSIGNED NULL,
    uploaded_at         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    replaced_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY student_document_file_key_unique (storage_key),
    KEY student_document_file_doc_idx (student_document_id, is_current),
    KEY student_document_file_uploader_idx (uploaded_by),
    CONSTRAINT student_document_file_doc_fk FOREIGN KEY (student_document_id)
        REFERENCES student_documents (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT student_document_file_uploader_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS student_document_reviews (
    id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    student_document_id INT UNSIGNED NOT NULL,
    action              VARCHAR(20)  NOT NULL,
    from_status         VARCHAR(20)  NULL,
    to_status           VARCHAR(20)  NOT NULL,
    remarks             VARCHAR(500) NULL,
    acted_by            INT UNSIGNED NULL,
    acted_at            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY student_document_review_doc_idx (student_document_id, acted_at),
    KEY student_document_review_user_idx (acted_by),
    CONSTRAINT student_document_review_action_chk CHECK (action IN ('uploaded', 'removed', 'under_review', 'verified', 'rejected', 'reused')),
    CONSTRAINT student_document_review_doc_fk FOREIGN KEY (student_document_id)
        REFERENCES student_documents (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT student_document_review_user_fk FOREIGN KEY (acted_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  6. Virtual classroom (one per batch)
--
--  Membership is not stored: the batch's teacher (batches.instructor_id)
--  and its active or completed trainees (batch_students) are the
--  classroom. Enrolling, removing or transferring a trainee changes
--  access at once, while their past submissions stay on record.
--  Stream posts are rows in the existing announcements table (batch_id).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS classroom_items (
    id           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    batch_id     INT UNSIGNED  NOT NULL,
    item_type    VARCHAR(20)   NOT NULL,
    topic        VARCHAR(100)  NULL,
    title        VARCHAR(200)  NOT NULL,
    instructions TEXT          NULL,
    link_url     VARCHAR(2048) NULL,
    due_at       DATETIME      NULL,
    max_points   SMALLINT UNSIGNED NULL,
    created_by   INT UNSIGNED  NULL,
    created_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP     NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY classroom_item_batch_idx (batch_id, item_type, created_at),
    KEY classroom_item_created_by_idx (created_by),
    CONSTRAINT classroom_item_type_chk CHECK (item_type IN ('material', 'assignment')),
    CONSTRAINT classroom_item_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_item_created_by_fk FOREIGN KEY (created_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_submissions (
    id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    item_id      INT UNSIGNED NOT NULL,
    student_id   INT UNSIGNED NOT NULL,
    status       VARCHAR(20)  NOT NULL DEFAULT 'submitted',
    answer_text  TEXT         NULL,
    submitted_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_late      TINYINT(1)   NOT NULL DEFAULT 0,
    grade        DECIMAL(6,2) NULL,
    feedback     TEXT         NULL,
    graded_by    INT UNSIGNED NULL,
    graded_at    DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY classroom_submission_unique (item_id, student_id),
    KEY classroom_submission_student_idx (student_id),
    KEY classroom_submission_grader_idx (graded_by),
    CONSTRAINT classroom_submission_status_chk CHECK (status IN ('submitted', 'graded')),
    CONSTRAINT classroom_submission_item_fk FOREIGN KEY (item_id)
        REFERENCES classroom_items (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_submission_student_fk FOREIGN KEY (student_id)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_submission_grader_fk FOREIGN KEY (graded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS classroom_files (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    batch_id      INT UNSIGNED NOT NULL,
    item_id       INT UNSIGNED NULL,
    submission_id INT UNSIGNED NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key   CHAR(36)     NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT UNSIGNED NOT NULL,
    uploaded_by   INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY classroom_file_key_unique (storage_key),
    KEY classroom_file_batch_idx (batch_id),
    KEY classroom_file_item_idx (item_id),
    KEY classroom_file_submission_idx (submission_id),
    KEY classroom_file_uploader_idx (uploaded_by),
    CONSTRAINT classroom_file_batch_fk FOREIGN KEY (batch_id)
        REFERENCES batches (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_file_item_fk FOREIGN KEY (item_id)
        REFERENCES classroom_items (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_file_submission_fk FOREIGN KEY (submission_id)
        REFERENCES classroom_submissions (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT classroom_file_uploader_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


DROP PROCEDURE IF EXISTS tn_add_column;
DROP PROCEDURE IF EXISTS tn_drop_check;
DROP PROCEDURE IF EXISTS tn_drop_index;
DROP PROCEDURE IF EXISTS tn_add_index;
