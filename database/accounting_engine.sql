-- ============================================================
--  Accounting Engine — Infrastructure (Phase 6, Step 1)
--  Engine: InnoDB   Charset: utf8mb4
--  Safe to re-run (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS / INSERT IGNORE)
--
--  Built on the Phase 1-5 forensically-recovered and physically-verified
--  Chart of Accounts / journal_entries / journal_lines / journal_number_sequences
--  (see C:\xampp\Empower_Accounting_Phase5\reports\Database_Architecture.md).
--  Does not alter any existing row's data — only adds columns, indexes, and
--  new tables, plus one seed row for the Legacy Accounting Period.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  financial_years — activation
-- ------------------------------------------------------------
ALTER TABLE `financial_years` ADD COLUMN IF NOT EXISTS
    `is_legacy` TINYINT(1) NOT NULL DEFAULT 0 AFTER `status`;

-- ------------------------------------------------------------
--  accounting_periods
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `accounting_periods` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `financial_year_id` INT UNSIGNED NOT NULL,
    `name`              VARCHAR(50)  NOT NULL,
    `start_date`        DATE         NOT NULL,
    `end_date`          DATE         NOT NULL,
    `status`            ENUM('open','closed') NOT NULL DEFAULT 'open',
    `closed_by`         INT UNSIGNED NULL,
    `closed_at`         TIMESTAMP    NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_period_range` (`financial_year_id`, `start_date`, `end_date`),
    KEY `idx_period_status` (`status`),
    CONSTRAINT `fk_ap_financial_year` FOREIGN KEY (`financial_year_id`) REFERENCES `financial_years`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ap_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  opening_balances
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `opening_balances` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_id`        INT UNSIGNED NOT NULL,
    `financial_year_id` INT UNSIGNED NOT NULL,
    `amount`            DECIMAL(15,2) NOT NULL,
    `as_of_date`        DATE         NOT NULL,
    `status`            ENUM('draft','approved') NOT NULL DEFAULT 'draft',
    `entered_by`        INT UNSIGNED NOT NULL,
    `approved_by`       INT UNSIGNED NULL,
    `approved_at`       TIMESTAMP    NULL,
    `journal_entry_id`  INT UNSIGNED NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_opening_balance_account_year` (`account_id`, `financial_year_id`),
    CONSTRAINT `chk_ob_amount_nonneg` CHECK (`amount` >= 0),
    CONSTRAINT `fk_ob_account` FOREIGN KEY (`account_id`) REFERENCES `accounts`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ob_financial_year` FOREIGN KEY (`financial_year_id`) REFERENCES `financial_years`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ob_entered_by` FOREIGN KEY (`entered_by`) REFERENCES `users`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_ob_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_ob_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  journal_entry_audit
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `journal_entry_audit` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NOT NULL,
    `action`      VARCHAR(50)  NOT NULL,
    `entity_type` VARCHAR(50)  NOT NULL,
    `entity_id`   INT UNSIGNED NOT NULL,
    `before_json` JSON NULL,
    `after_json`  JSON NOT NULL,
    `reason`      TEXT NULL,
    `ip_address`  VARCHAR(45)  NULL,
    `created_at`  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_user` (`user_id`),
    KEY `idx_audit_created` (`created_at`),
    CONSTRAINT `fk_audit_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  journal_entries — extension
-- ------------------------------------------------------------
ALTER TABLE `journal_entries` ADD COLUMN IF NOT EXISTS
    `accounting_period_id` INT UNSIGNED NULL AFTER `financial_year_id`;

-- MariaDB 10.4 has no IF NOT EXISTS for ADD CONSTRAINT (10.5.2+ only);
-- safe here because journal_entries was freshly recreated this session
-- and does not yet have this FK.
ALTER TABLE `journal_entries` ADD CONSTRAINT
    `fk_je_accounting_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`) ON DELETE RESTRICT;

ALTER TABLE `journal_entries` ADD UNIQUE INDEX IF NOT EXISTS
    `uk_source_reference` (`source_module`, `source_reference_type`, `source_reference_id`);

-- ------------------------------------------------------------
--  Legacy Accounting Period — financial_years seed row
--  Houses the 43 forensically-recovered legacy journal entries.
--  Not yet linked (journal_entries.financial_year_id reassignment
--  is a deliberate future step, not part of this migration).
-- ------------------------------------------------------------
INSERT IGNORE INTO `financial_years` (`id`, `name`, `start_date`, `end_date`, `status`, `is_legacy`) VALUES
    (1, 'Legacy Accounting Period', '2026-08-20', '2026-08-22', 'closed', 1);
