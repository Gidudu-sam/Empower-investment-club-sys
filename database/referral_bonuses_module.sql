-- ============================================================
--  Referral Commissions & Bonuses Module
--  Engine: InnoDB   Charset: utf8mb4
--
--  One new GL expense account + one new table. Reuses accounts,
--  members, users, journal_entries, journal_number_sequences.
--  No existing table, row, or journal entry is touched.
--  Cashier-facing, immediate post-on-record (like member_fees
--  markPaid()) rather than the draft/approve shape used by
--  expenses/internal_vouchers -- there is no "pending" state for
--  a bonus, it's decided and paid in the same moment.
-- ============================================================

USE `empower_db`;

-- New GL expense account. Next free expense code after 5340 (Airtime).
INSERT INTO `accounts` (`code`, `name`, `type`, `is_active`)
SELECT '5350', 'Referral Commissions & Bonuses', 'expense', 1
WHERE NOT EXISTS (SELECT 1 FROM `accounts` WHERE `code` = '5350');

CREATE TABLE IF NOT EXISTS `referral_bonuses` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `reference_number`      VARCHAR(20) NOT NULL,
    `bonus_type`            ENUM('referral','staff_target') NOT NULL,
    `beneficiary_member_id` INT UNSIGNED NOT NULL COMMENT 'The member receiving the payout -- staff are also members',
    `referred_member_id`    INT UNSIGNED NULL COMMENT 'referral type only: the new member who was brought in',
    `target_note`           VARCHAR(255) NULL COMMENT 'staff_target type only: free-text description of the target hit -- no target-tracking system exists to validate against',
    `amount`                DECIMAL(15,2) NOT NULL,
    `payment_date`          DATE NOT NULL,
    `payment_method`        ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `narration`             VARCHAR(255) NULL,
    `recorded_by`           INT UNSIGNED NOT NULL,
    `journal_entry_id`      INT UNSIGNED NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_referral_bonus_reference` (`reference_number`),
    KEY `idx_referral_bonus_beneficiary` (`beneficiary_member_id`),
    KEY `idx_referral_bonus_referred` (`referred_member_id`),
    KEY `idx_referral_bonus_type` (`bonus_type`),
    CONSTRAINT `fk_referral_bonus_beneficiary` FOREIGN KEY (`beneficiary_member_id`) REFERENCES `members`(`id`),
    CONSTRAINT `fk_referral_bonus_referred` FOREIGN KEY (`referred_member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_referral_bonus_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`),
    CONSTRAINT `fk_referral_bonus_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbering — reuse the existing row-locked sequence mechanism.
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('RB', 0);
