-- ============================================================
--  Internal Vouchers Module (Debit/Credit Vouchers)
--  Engine: InnoDB   Charset: utf8mb4
--
--  One new table only. Reuses expense_categories (not duplicated),
--  accounts (resolved by id at write time, never hardcoded), users,
--  journal_entries. No existing table, row, or journal entry is
--  touched. Mirrors the investments/opening_balance_batches
--  maker-checker shape exactly.
-- ============================================================

USE `empower_db`;

CREATE TABLE `internal_vouchers` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `voucher_number`        VARCHAR(20) NOT NULL,
    `voucher_type`          ENUM('debit','credit') NOT NULL,
    `voucher_date`          DATE NOT NULL,
    `primary_account_id`    INT UNSIGNED NOT NULL COMMENT 'Debit account for a Debit Voucher, Credit account for a Credit Voucher',
    `contra_account_id`     INT UNSIGNED NOT NULL,
    `expense_category_id`   INT UNSIGNED NULL COMMENT 'Optional: for debit vouchers routed through an expense category, its mapped GL account becomes primary_account_id',
    `narration`             VARCHAR(255) NOT NULL COMMENT 'The "Being" field on the physical voucher',
    `amount`                DECIMAL(15,2) NOT NULL,
    `status`                ENUM('draft','pending_approval','approved','rejected','posted') NOT NULL DEFAULT 'draft',
    `recorded_by`           INT UNSIGNED NOT NULL COMMENT 'Prepared By',
    `submitted_at`          TIMESTAMP NULL DEFAULT NULL,
    `approved_by`           INT UNSIGNED NULL COMMENT 'Approved By',
    `approved_at`           TIMESTAMP NULL DEFAULT NULL,
    `rejected_by`           INT UNSIGNED NULL,
    `rejected_at`           TIMESTAMP NULL DEFAULT NULL,
    `rejection_reason`      TEXT NULL,
    `posted_at`             TIMESTAMP NULL DEFAULT NULL,
    `journal_entry_id`      INT UNSIGNED NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_voucher_number` (`voucher_number`),
    KEY `idx_voucher_status` (`status`),
    KEY `idx_voucher_type` (`voucher_type`),
    CONSTRAINT `fk_voucher_primary_account` FOREIGN KEY (`primary_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_voucher_contra_account` FOREIGN KEY (`contra_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_voucher_expense_category` FOREIGN KEY (`expense_category_id`) REFERENCES `expense_categories`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_voucher_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_voucher_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_voucher_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_voucher_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbering — reuse the existing row-locked sequence mechanism
-- (already used for EXP-000001, OB-000001, INV-000001, INVTX-000001).
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('IV', 0);
