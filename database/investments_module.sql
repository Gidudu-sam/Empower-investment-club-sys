-- ============================================================
--  Investments Module (Task: Investments)
--  Engine: InnoDB   Charset: utf8mb4
--
--  New tables only: investment_types, investments,
--  investment_transactions. Accounts resolved by CODE, never a
--  hardcoded id. Adds exactly one new Chart of Accounts row:
--  5310 Loss on Investment Disposal (expense) -- the next free
--  5xxx code after 5300, per the pre-flight check. No existing
--  table, row, or journal entry is touched by this migration.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  One new Chart of Accounts row: Loss on Investment Disposal.
--  Needed because a below-carrying-amount disposal has no
--  existing home in the chart (verified: zero gain/loss accounts
--  existed before this).
-- ------------------------------------------------------------
INSERT INTO `accounts` (`code`, `name`, `type`, `subtype`, `normal_balance`, `is_system`, `is_active`, `description`)
VALUES ('5310', 'Loss on Investment Disposal', 'expense', 'operating_expense', 'debit', 1, 1,
        'Recognizes a loss when an investment is disposed of below its carrying amount');

-- ------------------------------------------------------------
--  investment_types — config: GL mapping per type, admin-editable
-- ------------------------------------------------------------
CREATE TABLE `investment_types` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `type_name`             VARCHAR(100) NOT NULL,
    `asset_gl_account_id`   INT UNSIGNED NOT NULL,
    `income_gl_account_id`  INT UNSIGNED NOT NULL,
    `loss_gl_account_id`    INT UNSIGNED NULL,
    `description`           VARCHAR(255) NULL,
    `is_active`             TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_invtype_name` (`type_name`),
    CONSTRAINT `fk_invtype_asset_account` FOREIGN KEY (`asset_gl_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_invtype_income_account` FOREIGN KEY (`income_gl_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_invtype_loss_account` FOREIGN KEY (`loss_gl_account_id`) REFERENCES `accounts`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  investments — the source document. Maker-checker workflow
--  mirrors opening_balance_batches exactly.
-- ------------------------------------------------------------
CREATE TABLE `investments` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `investment_number`     VARCHAR(20) NOT NULL,
    `investment_type_id`    INT UNSIGNED NOT NULL,
    `reference`             VARCHAR(100) NULL,
    `provider_name`         VARCHAR(150) NULL,
    `principal_amount`      DECIMAL(15,2) NOT NULL,
    `start_date`            DATE NOT NULL,
    `maturity_date`         DATE NULL,
    `expected_rate`         DECIMAL(10,6) NULL,
    `investment_account_id` INT UNSIGNED NOT NULL,
    `funding_account_id`    INT UNSIGNED NOT NULL,
    `status`                ENUM('draft','pending_approval','approved','rejected','posted','matured','withdrawn','disposed') NOT NULL DEFAULT 'draft',
    `notes`                 TEXT NULL,
    `recorded_by`           INT UNSIGNED NOT NULL,
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
    UNIQUE KEY `uk_investment_number` (`investment_number`),
    KEY `idx_investment_status` (`status`),
    KEY `idx_investment_type` (`investment_type_id`),
    CONSTRAINT `fk_investment_type` FOREIGN KEY (`investment_type_id`) REFERENCES `investment_types`(`id`),
    CONSTRAINT `fk_investment_asset_account` FOREIGN KEY (`investment_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_investment_funding_account` FOREIGN KEY (`funding_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_investment_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_investment_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_investment_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_investment_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  investment_transactions — child events after placement:
--  income, withdrawal, disposal/maturity. Simple draft->posted
--  workflow (no maker-checker) for V1.
-- ------------------------------------------------------------
CREATE TABLE `investment_transactions` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_number`    VARCHAR(20) NOT NULL,
    `investment_id`         INT UNSIGNED NOT NULL,
    `transaction_type`      ENUM('income','withdrawal','disposal') NOT NULL,
    `amount`                DECIMAL(15,2) NOT NULL,
    `transaction_date`      DATE NOT NULL,
    `description`           VARCHAR(255) NULL,
    `funding_account_id`    INT UNSIGNED NOT NULL,
    `status`                ENUM('draft','posted') NOT NULL DEFAULT 'draft',
    `recorded_by`           INT UNSIGNED NOT NULL,
    `journal_entry_id`      INT UNSIGNED NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_invtx_number` (`transaction_number`),
    KEY `idx_invtx_investment` (`investment_id`),
    KEY `idx_invtx_type` (`transaction_type`),
    CONSTRAINT `fk_invtx_investment` FOREIGN KEY (`investment_id`) REFERENCES `investments`(`id`),
    CONSTRAINT `fk_invtx_funding_account` FOREIGN KEY (`funding_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_invtx_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_invtx_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Numbering — reuse the existing row-locked sequence mechanism
--  (already used for EXP-000001, OB-000001; JE is the journal
--  entry sequence — separate prefix, same table, no collision).
-- ------------------------------------------------------------
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('INV', 0);
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('INVTX', 0);

-- ------------------------------------------------------------
--  Seed investment types — accounts resolved by CODE, not id.
-- ------------------------------------------------------------
INSERT INTO `investment_types` (`type_name`, `asset_gl_account_id`, `income_gl_account_id`, `loss_gl_account_id`, `description`)
SELECT 'Fixed Deposit', a.id, i.id, l.id, 'Club-owned fixed/investment deposits held with a financial institution'
FROM `accounts` a, `accounts` i, `accounts` l WHERE a.code = '1040' AND i.code = '4030' AND l.code = '5310';

INSERT INTO `investment_types` (`type_name`, `asset_gl_account_id`, `income_gl_account_id`, `loss_gl_account_id`, `description`)
SELECT 'Treasury / Government Securities', a.id, i.id, l.id, 'Government securities and treasury investments'
FROM `accounts` a, `accounts` i, `accounts` l WHERE a.code = '1040' AND i.code = '4030' AND l.code = '5310';

INSERT INTO `investment_types` (`type_name`, `asset_gl_account_id`, `income_gl_account_id`, `loss_gl_account_id`, `description`)
SELECT 'Shares', a.id, i.id, l.id, 'Shares or securities held by the club'
FROM `accounts` a, `accounts` i, `accounts` l WHERE a.code = '1040' AND i.code = '4030' AND l.code = '5310';

INSERT INTO `investment_types` (`type_name`, `asset_gl_account_id`, `income_gl_account_id`, `loss_gl_account_id`, `description`)
SELECT 'Property', a.id, i.id, l.id, 'Club-owned property investments'
FROM `accounts` a, `accounts` i, `accounts` l WHERE a.code = '1040' AND i.code = '4160' AND l.code = '5310';

INSERT INTO `investment_types` (`type_name`, `asset_gl_account_id`, `income_gl_account_id`, `loss_gl_account_id`, `description`)
SELECT 'Other', a.id, i.id, l.id, 'Any other external investment not covered above'
FROM `accounts` a, `accounts` i, `accounts` l WHERE a.code = '1040' AND i.code = '4030' AND l.code = '5310';
