-- =====================================================================
--  TESDA NEXUS — Public blockchain anchoring and QR-verifiable documents
--
--  Layer 2: blockchain_anchors. The hash of the newest ledger block is
--  timestamped in the Bitcoin blockchain through OpenTimestamps (free, no
--  wallet). Every block contains the hash of the block before it, so one
--  anchor proves the whole chain up to that block existed unchanged at that
--  time. Only that 32-byte hash is sent out.
--
--  Layer 3: document_seals. A printed sheet carries a QR code with a random
--  token. The public verification page rebuilds the sheet's data from the
--  records and compares it with the fingerprint taken when it was printed,
--  checks the seal's ledger block, and reports its Bitcoin anchor. The
--  public page shows no personal data beyond what is needed to match the paper.
--
--  Safe to run more than once. MySQL 8.0.16+.
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS blockchain_anchors (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    ledger_id        INT UNSIGNED NOT NULL,
    block_index      INT UNSIGNED NOT NULL,
    block_hash       CHAR(64)     NOT NULL,
    network          VARCHAR(20)  NOT NULL DEFAULT 'bitcoin',
    method           VARCHAR(20)  NOT NULL DEFAULT 'opentimestamps',
    status           VARCHAR(20)  NOT NULL DEFAULT 'pending',
    proofs           MEDIUMTEXT   NOT NULL,
    bitcoin_height   INT UNSIGNED NULL,
    bitcoin_block    CHAR(64)     NULL,
    bitcoin_time     DATETIME     NULL,
    merkle_root      CHAR(64)     NULL,
    submitted_by     INT UNSIGNED NULL,
    submitted_at     DATETIME     NOT NULL,
    confirmed_at     DATETIME     NULL,
    last_checked_at  DATETIME     NULL,
    last_error       VARCHAR(500) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY blockchain_anchor_block_unique (ledger_id),
    KEY blockchain_anchor_status_idx (status, submitted_at),
    KEY blockchain_anchor_index_idx (block_index),
    KEY blockchain_anchor_submitted_by_idx (submitted_by),
    CONSTRAINT blockchain_anchor_status_chk CHECK (status IN ('pending', 'confirmed', 'failed')),
    CONSTRAINT blockchain_anchor_ledger_fk FOREIGN KEY (ledger_id)
        REFERENCES blockchain_ledger (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT blockchain_anchor_submitted_by_fk FOREIGN KEY (submitted_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_seals (
    id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
    token              CHAR(24)     NOT NULL,
    kind               VARCHAR(30)  NOT NULL,
    entity_id          INT UNSIGNED NOT NULL,
    training_center_id INT UNSIGNED NULL,
    snapshot_hash      CHAR(64)     NOT NULL,
    ledger_id          INT UNSIGNED NULL,
    issued_by          INT UNSIGNED NULL,
    issued_at          DATETIME     NOT NULL,
    revoked_at         DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY document_seal_token_unique (token),
    KEY document_seal_entity_idx (kind, entity_id, snapshot_hash),
    KEY document_seal_center_idx (training_center_id),
    KEY document_seal_ledger_idx (ledger_id),
    KEY document_seal_issued_by_idx (issued_by),
    CONSTRAINT document_seal_kind_chk CHECK (kind IN ('student_info', 'student_checklist', 'enrollment_packet', 'batch_records')),
    CONSTRAINT document_seal_center_fk FOREIGN KEY (training_center_id)
        REFERENCES training_centers (id) ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT document_seal_ledger_fk FOREIGN KEY (ledger_id)
        REFERENCES blockchain_ledger (id) ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT document_seal_issued_by_fk FOREIGN KEY (issued_by)
        REFERENCES users (id) ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seals are recorded on the ledger as 'seal' blocks.
SET @chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'blockchain_ledger'
               AND CONSTRAINT_NAME = 'ledger_entity_type_chk' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@chk = 1, 'ALTER TABLE blockchain_ledger DROP CHECK ledger_entity_type_chk', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE blockchain_ledger ADD CONSTRAINT ledger_entity_type_chk
    CHECK (entity_type IN ('system', 'document', 'student_document', 'inventory_request', 'inventory_delivery',
                           'allowance_request', 'stock_movement', 'batch', 'seal'));
