-- ============================================================
--  STAGE 17 PART D — Other Income: New Clean Architecture
--  Creates two NEW tables, modeled on expense_categories/expenses.
--  Does NOT touch, repair, or reference the legacy `other_income`
--  table in any way — that table remains an untouched forensic
--  artifact. `other_income_transactions` is a distinct, unrelated
--  table with no data or foreign-key relationship to it.
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
-- other_income_categories
--   Mirrors expense_categories exactly in shape. The application
--   layer (OtherIncomeCategoryModel/Controller) restricts
--   gl_account_id selection to accounts.type='income' AND
--   is_active=1 -- MariaDB CHECK constraints cannot reference
--   another table, so this cannot be enforced at the schema level,
--   same limitation expense_categories already has for type='expense'.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `other_income_categories` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category_name`  VARCHAR(150) NOT NULL,
    `description`    VARCHAR(255) DEFAULT NULL,
    `gl_account_id`  INT UNSIGNED DEFAULT NULL,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    CONSTRAINT `fk_oicat_account` FOREIGN KEY (`gl_account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- other_income_transactions
--   Mirrors expenses' draft/posted lifecycle exactly. No
--   financial_year_id/accounting_period_id columns -- JournalService
--   already derives and stores those on the journal_entries row
--   itself, same convention Fees (Stage 17 Part B) established.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `other_income_transactions` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `income_number`     VARCHAR(20) NOT NULL,
    `category_id`       INT UNSIGNED NOT NULL,
    `income_date`       DATE NOT NULL,
    `amount`            DECIMAL(15,2) NOT NULL,
    `description`       VARCHAR(255) DEFAULT NULL,
    `reference_number`  VARCHAR(100) DEFAULT NULL,
    `payment_method`    ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `financial_year`    YEAR(4) DEFAULT NULL,
    `status`            ENUM('draft','posted') NOT NULL DEFAULT 'draft',
    `recorded_by`       INT UNSIGNED NOT NULL,
    `posted_by`         INT UNSIGNED DEFAULT NULL,
    `posted_at`         TIMESTAMP NULL DEFAULT NULL,
    `journal_entry_id`  INT UNSIGNED DEFAULT NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_income_number` (`income_number`),
    KEY `idx_income_date` (`income_date`),
    KEY `idx_income_status` (`status`),
    KEY `fk_oi_category` (`category_id`),
    KEY `fk_oi_recorded_by` (`recorded_by`),
    KEY `fk_oi_posted_by` (`posted_by`),
    KEY `fk_oi_journal_entry` (`journal_entry_id`),
    CONSTRAINT `fk_oi_category` FOREIGN KEY (`category_id`) REFERENCES `other_income_categories` (`id`),
    CONSTRAINT `fk_oi_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_oi_posted_by` FOREIGN KEY (`posted_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_oi_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Numbering sequence, same mechanism used by Expenses (EXP),
-- Corporate accounts (CORP), Investment Vouchers (IV), etc.
-- Idempotent: does nothing if 'OI' already exists.
-- ------------------------------------------------------------
INSERT INTO `journal_number_sequences` (`prefix`, `last_number`)
VALUES ('OI', 0)
ON DUPLICATE KEY UPDATE `prefix` = `prefix`;
