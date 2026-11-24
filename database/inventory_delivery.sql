-- =====================================================================
--  TESDA NEXUS — Inventory request, approval, QR and delivery upgrade
--
--  Extends the existing supply request tables (inventory_requests,
--  inventory_request_items) and stock ledger (products, stock_movements):
--    * two main office roles: inventory_staff, delivery_staff
--    * request letters (composed in the app or uploaded) and attachments
--    * the request lifecycle:
--        draft -> pending -> approved -> processing -> ready_for_delivery
--              -> in_transit -> delivered -> completed
--        (pending -> rejected "Disapproved"; receipt with different
--         quantities -> discrepancy -> completed once resolved)
--    * QR deliveries (inventory_deliveries + inventory_delivery_items)
--
--  Stock leaves the main office ONLY when delivery personnel validate a
--  delivery's QR code and confirm the release. Approving a request or
--  generating its QR code never changes stock.
--
--  Safe to run more than once; existing rows are kept and their statuses
--  mapped (in_review -> pending, released -> in_transit,
--  received -> completed). MySQL 8.0.16+.
-- =====================================================================

SET NAMES utf8mb4;

DROP PROCEDURE IF EXISTS tn_add_column;
DROP PROCEDURE IF EXISTS tn_drop_check;
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
--  1. Main office roles. Inventory and delivery personnel are main office
--     accounts (training_center_id = the TESDA-MAIN center).
-- ---------------------------------------------------------------------
CALL tn_drop_check('users', 'user_role_chk');
ALTER TABLE users ADD CONSTRAINT user_role_chk
    CHECK (role IN ('admin', 'center_admin', 'instructor', 'student', 'inventory_staff', 'delivery_staff'));


-- ---------------------------------------------------------------------
--  2. Requests: letter, lifecycle timestamps, receipt and discrepancy
-- ---------------------------------------------------------------------
CALL tn_add_column('inventory_requests', 'letter_mode', "VARCHAR(10) NULL AFTER purpose");
CALL tn_add_column('inventory_requests', 'letter_date', 'DATE NULL AFTER letter_mode');
CALL tn_add_column('inventory_requests', 'letter_recipient', 'VARCHAR(255) NULL AFTER letter_date');
CALL tn_add_column('inventory_requests', 'letter_subject', 'VARCHAR(200) NULL AFTER letter_recipient');
CALL tn_add_column('inventory_requests', 'letter_body', 'TEXT NULL AFTER letter_subject');
CALL tn_add_column('inventory_requests', 'letter_signatory', 'VARCHAR(150) NULL AFTER letter_body');
CALL tn_add_column('inventory_requests', 'letter_position', 'VARCHAR(150) NULL AFTER letter_signatory');
CALL tn_add_column('inventory_requests', 'submitted_at', 'DATETIME NULL AFTER letter_position');
CALL tn_add_column('inventory_requests', 'processing_by', 'INT UNSIGNED NULL AFTER reviewed_at');
CALL tn_add_column('inventory_requests', 'processing_at', 'DATETIME NULL AFTER processing_by');
CALL tn_add_column('inventory_requests', 'received_by', 'INT UNSIGNED NULL AFTER received_at');
CALL tn_add_column('inventory_requests', 'receipt_notes', 'TEXT NULL AFTER received_by');
CALL tn_add_column('inventory_requests', 'resolved_by', 'INT UNSIGNED NULL AFTER receipt_notes');
CALL tn_add_column('inventory_requests', 'resolved_at', 'DATETIME NULL AFTER resolved_by');
CALL tn_add_column('inventory_requests', 'resolution_notes', 'TEXT NULL AFTER resolved_at');

CALL tn_drop_check('inventory_requests', 'inventory_request_status_chk');
CALL tn_drop_check('inventory_requests', 'inventory_request_letter_mode_chk');
UPDATE inventory_requests SET status = 'pending' WHERE status = 'in_review';
UPDATE inventory_requests SET status = 'in_transit' WHERE status = 'released';
UPDATE inventory_requests SET status = 'completed' WHERE status = 'received';
UPDATE inventory_requests SET submitted_at = created_at WHERE submitted_at IS NULL AND status <> 'draft';
ALTER TABLE inventory_requests
    ADD CONSTRAINT inventory_request_status_chk CHECK (status IN (
        'draft', 'pending', 'approved', 'rejected', 'processing', 'ready_for_delivery',
        'in_transit', 'delivered', 'completed', 'discrepancy', 'cancelled')),
    ADD CONSTRAINT inventory_request_letter_mode_chk CHECK (letter_mode IS NULL OR letter_mode IN ('composed', 'uploaded'));
CALL tn_add_index('inventory_requests', 'inventory_request_processing_by_idx', 'KEY inventory_request_processing_by_idx (processing_by)');
CALL tn_add_index('inventory_requests', 'inventory_request_received_by_idx', 'KEY inventory_request_received_by_idx (received_by)');
CALL tn_add_index('inventory_requests', 'inventory_request_resolved_by_idx', 'KEY inventory_request_resolved_by_idx (resolved_by)');

CALL tn_add_column('inventory_request_items', 'justification', 'VARCHAR(500) NULL AFTER quantity_approved');
CALL tn_add_column('inventory_request_items', 'quantity_received', 'INT UNSIGNED NULL AFTER justification');


-- ---------------------------------------------------------------------
--  3. Request letter and supporting photos (stored privately under
--     runtime/inventory-requests, served only through the API).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_request_files (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_id    INT UNSIGNED NOT NULL,
    kind          VARCHAR(12)  NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    storage_key   CHAR(36)     NOT NULL,
    mime_type     VARCHAR(100) NOT NULL,
    file_size     INT UNSIGNED NOT NULL,
    file_hash     CHAR(64)     NOT NULL,
    uploaded_by   INT UNSIGNED NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY inventory_request_file_key_unique (storage_key),
    KEY inventory_request_file_request_idx (request_id, kind),
    KEY inventory_request_file_uploader_idx (uploaded_by),
    CONSTRAINT inventory_request_file_kind_chk CHECK (kind IN ('letter', 'attachment')),
    CONSTRAINT inventory_request_file_request_fk FOREIGN KEY (request_id)
        REFERENCES inventory_requests (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT inventory_request_file_uploader_fk FOREIGN KEY (uploaded_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------
--  4. QR deliveries. The QR code holds only qr_token (random, unguessable);
--     the server looks everything else up. active_request_id equals
--     request_id while the delivery is not cancelled, and is UNIQUE, so a
--     request can never have two active QR codes.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS inventory_deliveries (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_code     VARCHAR(20)  NOT NULL,
    request_id        INT UNSIGNED NOT NULL,
    active_request_id INT UNSIGNED NULL,
    qr_token          CHAR(64)     NOT NULL,
    status            VARCHAR(20)  NOT NULL DEFAULT 'ready',
    prepared_by       INT UNSIGNED NULL,
    prepared_at       DATETIME     NOT NULL,
    released_by       INT UNSIGNED NULL,
    released_at       DATETIME     NULL,
    qr_used_at        DATETIME     NULL,
    delivered_by      INT UNSIGNED NULL,
    delivered_at      DATETIME     NULL,
    notes             VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY inventory_delivery_code_unique (delivery_code),
    UNIQUE KEY inventory_delivery_token_unique (qr_token),
    UNIQUE KEY inventory_delivery_active_unique (active_request_id),
    KEY inventory_delivery_request_idx (request_id),
    KEY inventory_delivery_status_idx (status, prepared_at),
    KEY inventory_delivery_prepared_by_idx (prepared_by),
    KEY inventory_delivery_released_by_idx (released_by),
    KEY inventory_delivery_delivered_by_idx (delivered_by),
    CONSTRAINT inventory_delivery_status_chk CHECK (status IN ('ready', 'in_transit', 'delivered', 'cancelled')),
    CONSTRAINT inventory_delivery_request_fk FOREIGN KEY (request_id)
        REFERENCES inventory_requests (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT inventory_delivery_prepared_by_fk FOREIGN KEY (prepared_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT inventory_delivery_released_by_fk FOREIGN KEY (released_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT inventory_delivery_delivered_by_fk FOREIGN KEY (delivered_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- The approved items and quantities, fixed when the QR code is generated.
CREATE TABLE IF NOT EXISTS inventory_delivery_items (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    delivery_id INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY inventory_delivery_item_unique (delivery_id, product_id),
    KEY inventory_delivery_item_product_idx (product_id),
    CONSTRAINT inventory_delivery_item_qty_chk CHECK (quantity > 0),
    CONSTRAINT inventory_delivery_item_delivery_fk FOREIGN KEY (delivery_id)
        REFERENCES inventory_deliveries (id) ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT inventory_delivery_item_product_fk FOREIGN KEY (product_id)
        REFERENCES products (id) ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Releases in the existing stock ledger point to their delivery as well.
CALL tn_add_column('stock_movements', 'delivery_id', 'INT UNSIGNED NULL AFTER request_id');
CALL tn_add_index('stock_movements', 'stock_movement_delivery_idx', 'KEY stock_movement_delivery_idx (delivery_id)');
SET @fk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
            WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'stock_movements'
              AND CONSTRAINT_NAME = 'stock_movement_delivery_fk');
SET @sql := IF(@fk = 0,
    'ALTER TABLE stock_movements ADD CONSTRAINT stock_movement_delivery_fk FOREIGN KEY (delivery_id) REFERENCES inventory_deliveries (id) ON UPDATE CASCADE ON DELETE SET NULL',
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Deliveries are recorded on the blockchain ledger too.
CALL tn_drop_check('blockchain_ledger', 'ledger_entity_type_chk');
ALTER TABLE blockchain_ledger ADD CONSTRAINT ledger_entity_type_chk
    CHECK (entity_type IN ('system', 'document', 'student_document', 'inventory_request', 'inventory_delivery', 'allowance_request', 'stock_movement'));


DROP PROCEDURE IF EXISTS tn_add_column;
DROP PROCEDURE IF EXISTS tn_drop_check;
DROP PROCEDURE IF EXISTS tn_add_index;
