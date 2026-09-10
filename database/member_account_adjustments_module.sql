-- ============================================================
--  Member Account Adjustments Module
--  Engine: InnoDB   Charset: utf8mb4
--
--  One new table. Reuses accounts, members, member_savings_accounts,
--  savings (as the member subsidiary ledger -- transaction_type
--  'adjustment' already existed in that ENUM, unused until now),
--  users, journal_entries, journal_number_sequences.
--  No existing table, row, or journal entry is touched.
--
--  Mirrors internal_vouchers' maker-checker shape (draft ->
--  pending_approval -> approved -> posted, or -> rejected) plus
--  reversal fields, since a posted adjustment is corrected by a
--  reversal, never edited or deleted -- see AdjustmentModel::reverse().
-- ============================================================

USE `empower_db`;

CREATE TABLE `member_account_adjustments` (
    `id`                         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `adjustment_number`          VARCHAR(20) NOT NULL,
    `member_id`                  INT UNSIGNED NOT NULL,
    `savings_account_id`         INT UNSIGNED NOT NULL,
    `adjustment_type`            ENUM('credit','debit') NOT NULL,
    `amount`                     DECIMAL(15,2) NOT NULL,
    `reason`                     TEXT NOT NULL,
    `original_reference`         VARCHAR(100) NULL COMMENT 'Free-text reference to the transaction being corrected, e.g. a savings receipt number',
    `contra_account_id`          INT UNSIGNED NOT NULL COMMENT 'The GL account on the other side of the correction -- selected by the preparer, same pattern as internal_vouchers.contra_account_id',
    `status`                     ENUM('draft','pending_approval','approved','rejected','posted') NOT NULL DEFAULT 'draft',
    `balance_before`             DECIMAL(15,2) NULL COMMENT 'Snapshot at post time',
    `balance_after`              DECIMAL(15,2) NULL,
    `savings_id`                 INT UNSIGNED NULL COMMENT 'The member-subsidiary-ledger row this adjustment created',
    `journal_entry_id`           INT UNSIGNED NULL COMMENT 'The GL journal entry this adjustment created',
    `recorded_by`                INT UNSIGNED NOT NULL COMMENT 'Prepared By',
    `submitted_at`                TIMESTAMP NULL DEFAULT NULL,
    `approved_by`                INT UNSIGNED NULL,
    `approved_at`                TIMESTAMP NULL DEFAULT NULL,
    `rejected_by`                INT UNSIGNED NULL,
    `rejected_at`                TIMESTAMP NULL DEFAULT NULL,
    `rejection_reason`           TEXT NULL,
    `posted_at`                  TIMESTAMP NULL DEFAULT NULL,
    `reversed_by`                INT UNSIGNED NULL,
    `reversed_at`                TIMESTAMP NULL DEFAULT NULL,
    `reversal_reason`            TEXT NULL,
    `reversal_journal_entry_id`  INT UNSIGNED NULL COMMENT 'The mirror-image GL entry created by JournalService::reverse()',
    `reversal_savings_id`        INT UNSIGNED NULL COMMENT 'The offsetting member-subsidiary-ledger row created on reversal',
    `created_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_adjustment_number` (`adjustment_number`),
    KEY `idx_adjustment_status` (`status`),
    KEY `idx_adjustment_member` (`member_id`),
    KEY `idx_adjustment_account` (`savings_account_id`),
    CONSTRAINT `fk_adj_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`),
    CONSTRAINT `fk_adj_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts`(`id`),
    CONSTRAINT `fk_adj_contra_account` FOREIGN KEY (`contra_account_id`) REFERENCES `accounts`(`id`),
    CONSTRAINT `fk_adj_savings` FOREIGN KEY (`savings_id`) REFERENCES `savings`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_adj_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_adj_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_adj_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_adj_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_adj_reversed_by` FOREIGN KEY (`reversed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_adj_reversal_savings` FOREIGN KEY (`reversal_savings_id`) REFERENCES `savings`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbering — reuse the existing row-locked sequence mechanism.
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('ADJ', 0);
