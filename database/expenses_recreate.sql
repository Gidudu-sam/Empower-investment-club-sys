-- ============================================================
--  Expenses & Expense Categories — Recreate (Step 7)
--  Engine: InnoDB   Charset: utf8mb4
--
--  These 2 tables were forensically extracted to JSON in Phase 3 but,
--  unlike accounts/journal_entries/journal_lines/journal_number_sequences
--  (fixed in Step 1), were never restored to a live state — confirmed
--  still broken ("doesn't exist in engine") at the start of Step 7.
--  Orphaned .ibd tablespace files removed and the broken .frm shells
--  dropped immediately before this migration. Schema below matches the
--  physically-confirmed column list from Empower_Forensic_AccountingPhase3
--  (.frm field pack + expenses.TRG trigger SQL, independently agreeing).
--  Data reload (11 categories, 1 expense) happens via a separate PHP
--  script preserving exact original IDs — this file is schema only.
-- ============================================================

USE `empower_db`;

CREATE TABLE `expense_categories` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `category_name` VARCHAR(150) NOT NULL,
    `description`   VARCHAR(255) NULL,
    `gl_account_id` INT UNSIGNED NULL,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_expcat_account` FOREIGN KEY (`gl_account_id`) REFERENCES `accounts`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `expenses` (
    `id`                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `expense_number`    VARCHAR(20) NOT NULL,
    `category_id`       INT UNSIGNED NOT NULL,
    `expense_date`      DATE NOT NULL,
    `amount`            DECIMAL(15,2) NOT NULL,
    `description`       VARCHAR(255) NULL,
    `reference_number`  VARCHAR(100) NULL,
    `payment_method`    ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `payee_name`        VARCHAR(150) NULL,
    `financial_year`    YEAR(4) NULL,
    `status`            ENUM('draft','posted') NOT NULL DEFAULT 'draft',
    `recorded_by`       INT UNSIGNED NOT NULL,
    `approved_by`       INT UNSIGNED NULL,
    `approved_date`     TIMESTAMP NULL DEFAULT NULL,
    `remarks`           VARCHAR(255) NULL,
    `journal_entry_id`  INT UNSIGNED NULL,
    `created_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_expense_number` (`expense_number`),
    KEY `idx_expense_date` (`expense_date`),
    KEY `idx_expense_status` (`status`),
    CONSTRAINT `fk_expense_category` FOREIGN KEY (`category_id`) REFERENCES `expense_categories`(`id`),
    CONSTRAINT `fk_expense_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_expense_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_expense_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Expense numbering — reuse the existing row-locked sequence mechanism.
-- Seeded at 1 since EXP-000001 is already taken by the historical record;
-- the next newly-created expense will be EXP-000002.
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('EXP', 1);
