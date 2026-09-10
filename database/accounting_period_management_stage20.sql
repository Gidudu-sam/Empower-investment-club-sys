-- ============================================================
--  STAGE 20 — Accounting Periods & Financial-Year Management
--  Additive only. Safe to re-run (guarded ADD COLUMN).
--  Adds audit/reopen metadata to accounting_periods and
--  financial_years. No historical row is modified by this file
--  (all new columns default to NULL and are populated only by
--  future close/reopen actions going forward).
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
-- accounting_periods: creator + close reason + reopen audit trail
-- ------------------------------------------------------------
ALTER TABLE `accounting_periods` ADD COLUMN IF NOT EXISTS
    `created_by` INT UNSIGNED NULL AFTER `financial_year_id`;

ALTER TABLE `accounting_periods` ADD COLUMN IF NOT EXISTS
    `close_reason` TEXT NULL AFTER `closed_at`;

ALTER TABLE `accounting_periods` ADD COLUMN IF NOT EXISTS
    `reopened_by` INT UNSIGNED NULL AFTER `close_reason`;

ALTER TABLE `accounting_periods` ADD COLUMN IF NOT EXISTS
    `reopened_at` TIMESTAMP NULL AFTER `reopened_by`;

ALTER TABLE `accounting_periods` ADD COLUMN IF NOT EXISTS
    `reopen_reason` TEXT NULL AFTER `reopened_at`;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounting_periods'
      AND CONSTRAINT_NAME = 'fk_ap_created_by'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `accounting_periods` ADD CONSTRAINT `fk_ap_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'accounting_periods'
      AND CONSTRAINT_NAME = 'fk_ap_reopened_by'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `accounting_periods` ADD CONSTRAINT `fk_ap_reopened_by` FOREIGN KEY (`reopened_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- financial_years: close + reopen audit trail (no closed_by/at existed at all)
-- ------------------------------------------------------------
ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `closed_by` INT UNSIGNED NULL AFTER `is_legacy`;

ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `closed_at` TIMESTAMP NULL AFTER `closed_by`;

ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `close_reason` TEXT NULL AFTER `closed_at`;

ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `reopened_by` INT UNSIGNED NULL AFTER `close_reason`;

ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `reopened_at` TIMESTAMP NULL AFTER `reopened_by`;

ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `reopen_reason` TEXT NULL AFTER `reopened_at`;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'financial_years'
      AND CONSTRAINT_NAME = 'fk_fy_closed_by'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `financial_years` ADD CONSTRAINT `fk_fy_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'financial_years'
      AND CONSTRAINT_NAME = 'fk_fy_reopened_by'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `financial_years` ADD CONSTRAINT `fk_fy_reopened_by` FOREIGN KEY (`reopened_by`) REFERENCES `users`(`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
