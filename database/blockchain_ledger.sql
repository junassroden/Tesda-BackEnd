-- =====================================================================
--  TESDA NEXUS — Blockchain ledger verification upgrade
--
--  The ledger stores only SHA-256 fingerprints, never the data itself
--  (no personal data leaves the records). To verify a block, the app
--  rebuilds the block's payload from the current records and compares
--  fingerprints. payload_format says how a block's payload was built:
--    genesis  the fixed genesis string
--    seed     demo blocks from tesda_nexus.sql, hashed from hand-written
--             strings: their place in the chain can be checked, their
--             content cannot be rebuilt from records
--    v0       blocks written by the app before this upgrade
--    v1       blocks written from now on (stable field order)
--  Also allows 'batch' entities (completed training batches).
--
--  Safe to run more than once. MySQL 8.0.16+.
-- =====================================================================

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blockchain_ledger' AND COLUMN_NAME = 'payload_format');
SET @sql := IF(@col = 0,
    "ALTER TABLE blockchain_ledger ADD COLUMN payload_format VARCHAR(10) NOT NULL DEFAULT 'v1' AFTER data_hash",
    'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Existing blocks, only when first added (a re-run keeps what is there).
UPDATE blockchain_ledger SET payload_format = 'v0'
 WHERE @col = 0;
UPDATE blockchain_ledger SET payload_format = 'genesis'
 WHERE entity_type = 'system' AND action = 'genesis' AND data_hash = SHA2('TESDA NEXUS genesis block', 256);
UPDATE blockchain_ledger SET payload_format = 'seed'
 WHERE data_hash IN (SHA2('REQ-0002|LC-ORMIN|goggles:6|first_aid_kit:1', 256),
                     SHA2('ALW-0002|LC-PALAWAN|students:2|total:5000.00', 256));

SET @chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'blockchain_ledger'
               AND CONSTRAINT_NAME = 'ledger_entity_type_chk' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@chk = 1, 'ALTER TABLE blockchain_ledger DROP CHECK ledger_entity_type_chk', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE blockchain_ledger ADD CONSTRAINT ledger_entity_type_chk
    CHECK (entity_type IN ('system', 'document', 'student_document', 'inventory_request', 'inventory_delivery',
                           'allowance_request', 'stock_movement', 'batch'));

SET @chk := (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'blockchain_ledger'
               AND CONSTRAINT_NAME = 'ledger_payload_format_chk' AND CONSTRAINT_TYPE = 'CHECK');
SET @sql := IF(@chk = 1, 'ALTER TABLE blockchain_ledger DROP CHECK ledger_payload_format_chk', 'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
ALTER TABLE blockchain_ledger ADD CONSTRAINT ledger_payload_format_chk
    CHECK (payload_format IN ('genesis', 'seed', 'v0', 'v1'));
