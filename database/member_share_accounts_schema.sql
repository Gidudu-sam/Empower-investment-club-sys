-- ============================================================
--  Member Share Accounts — STAGE: Share Architecture
--  Engine: InnoDB   Charset: utf8mb4
--
--  Business Requirement: Each member must have exactly ONE
--  independent Share Account. No share classes, no compulsory/
--  voluntary distinction, no multiple account types.
--
--  Simple member → share account relationship:
--      members (1) ←→ (1) member_share_accounts
--
--  This table does NOT replace or modify share_transactions.
--  It provides the account architecture that share_transactions
--  will reference via foreign key.
--
--  IMPORTANT: This is purely additive. No existing data is
--  touched. Historical share_transactions remain intact and
--  will be linked via population script after this schema is
--  applied.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  Member Share Accounts
--  One share account per member - enforced by UNIQUE(member_id)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_share_accounts` (
    `id`                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id`            INT UNSIGNED NOT NULL COMMENT 'One-to-one with members',
    `account_number`       VARCHAR(20) NOT NULL COMMENT 'e.g. SHR-000001',
    `status`               ENUM('active','dormant','closed') NOT NULL DEFAULT 'active',
    `opened_date`          DATE NOT NULL COMMENT 'Date account was created',
    `closed_date`          DATE NULL COMMENT 'NULL unless status = closed',
    `created_by`           INT UNSIGNED NULL COMMENT 'User who created this account',
    `created_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_member_share_account` (`member_id`) COMMENT 'One share account per member',
    UNIQUE KEY `uk_share_account_number` (`account_number`) COMMENT 'Account number must be unique',
    KEY `idx_share_account_status` (`status`),
    
    CONSTRAINT `fk_msha_member` 
        FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_msha_created_by` 
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
COMMENT='Member Share Accounts - one share account per member';

-- ------------------------------------------------------------
--  Modify share_transactions to reference share accounts
--  Additive only - nullable initially to allow historical linking
-- ------------------------------------------------------------
ALTER TABLE `share_transactions` 
    ADD COLUMN IF NOT EXISTS `share_account_id` INT UNSIGNED NULL 
    AFTER `member_id`
    COMMENT 'FK to member_share_accounts - nullable during migration only';

ALTER TABLE `share_transactions` 
    ADD KEY IF NOT EXISTS `idx_share_account` (`share_account_id`);

ALTER TABLE `share_transactions` 
    ADD CONSTRAINT `fk_share_txn_account` 
    FOREIGN KEY (`share_account_id`) REFERENCES `member_share_accounts`(`id`)
    ON DELETE RESTRICT;

-- ------------------------------------------------------------
--  Account number sequence for Share Accounts
--  Reuses existing journal_number_sequences mechanism
--  SHR prefix already exists in production (last_number = 0)
-- ------------------------------------------------------------
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) 
VALUES ('SHR', 0);

-- ============================================================
--  VERIFICATION QUERIES (run after applying this schema)
-- ============================================================
-- 
-- Check table was created:
--   SELECT COUNT(*) FROM member_share_accounts;
--
-- Verify share_transactions column added:
--   DESCRIBE share_transactions;
--
-- Check sequence exists:
--   SELECT * FROM journal_number_sequences WHERE prefix = 'SHR';
--
-- ============================================================
