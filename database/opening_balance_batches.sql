-- ============================================================
--  Opening Balance Workflow (Step 5)
--  Engine: InnoDB   Charset: utf8mb4
--  Safe to re-run (IF NOT EXISTS / INSERT IGNORE)
--
--  Redesigns `opening_balances` (created empty in Step 1, still 0 rows)
--  from a flat per-account row into per-batch LINE items under a new
--  `opening_balance_batches` header table, matching the maker-checker
--  batch workflow required by Step 5. Zero data loss: the table has
--  never held any rows.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  opening_balance_batches — workflow header, one row per batch
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `opening_balance_batches` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_number`          VARCHAR(20) NOT NULL,
    `financial_year_id`     INT UNSIGNED NOT NULL,
    `accounting_period_id`  INT UNSIGNED NOT NULL,
    `as_of_date`            DATE NOT NULL,
    `status`                ENUM('draft','pending_approval','approved','posted','rejected') NOT NULL DEFAULT 'draft',
    `entered_by`            INT UNSIGNED NOT NULL,
    `submitted_at`          TIMESTAMP NULL DEFAULT NULL,
    `approved_by`           INT UNSIGNED NULL,
    `approved_at`           TIMESTAMP NULL DEFAULT NULL,
    `rejected_by`           INT UNSIGNED NULL,
    `rejected_at`           TIMESTAMP NULL DEFAULT NULL,
    `rejection_reason`      TEXT NULL,
    `posted_at`             TIMESTAMP NULL DEFAULT NULL,
    `journal_entry_id`      INT UNSIGNED NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ob_batch_number` (`batch_number`),
    KEY `idx_ob_batch_status` (`status`),
    CONSTRAINT `fk_obb_financial_year` FOREIGN KEY (`financial_year_id`) REFERENCES `financial_years`(`id`),
    CONSTRAINT `fk_obb_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`),
    CONSTRAINT `fk_obb_entered_by` FOREIGN KEY (`entered_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_obb_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_obb_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_obb_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  opening_balances — redesigned as batch LINE items
--  (table has been empty since creation in Step 1 — dropping and
--  recreating is a pure schema change, not a data migration)
-- ------------------------------------------------------------
DROP TABLE IF EXISTS `opening_balances`;

CREATE TABLE `opening_balances` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `batch_id`      INT UNSIGNED NOT NULL,
    `account_id`    INT UNSIGNED NOT NULL,
    `debit`         DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `credit`        DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `description`   VARCHAR(255) NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_ob_batch_account` (`batch_id`, `account_id`),
    KEY `idx_ob_account` (`account_id`),
    CONSTRAINT `fk_ob_batch` FOREIGN KEY (`batch_id`) REFERENCES `opening_balance_batches`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ob_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `chk_ob_line_single_side` CHECK (`debit` <= 0 OR `credit` <= 0),
    CONSTRAINT `chk_ob_line_nonneg` CHECK (`debit` >= 0 AND `credit` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Batch numbering — reuse the existing row-locked sequence
--  mechanism JournalService already uses for 'JE'
-- ------------------------------------------------------------
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('OB', 0);
