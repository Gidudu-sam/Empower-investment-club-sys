-- Shares Module — Stage 1 (Foundation & Read-Only Workspace, 2026-09).
-- Purely additive: one new table. Nothing existing is altered.
--
-- share_transactions is the Shares ownership/history ledger. It is NOT a
-- second accounting engine and NOT a competing balance-of-record: GL 3010
-- (Shares — Share Capital) via JournalService remains the sole financial
-- authority. This table records WHO owns WHAT quantity of shares and WHY,
-- for reporting/ownership-percentage purposes only.
--
-- Approved policy (Shares Business Policy & Implementation Specification,
-- 2026-09-10):
--   - one ordinary share class; origin distinguished by transaction_type
--   - fractional quantities ARE allowed for retained_withdrawal events
--     (quantity = retained_amount / share_value_at_transaction, never
--     floored) -- see ShareModel for the read-side calculation
--   - quantity/share_value are captured PER ROW at the value in effect on
--     that transaction's date; never recalculated from a later-changed
--     settings.share_value (no retroactive revaluation)
--   - direct purchases are OUT OF SCOPE for Stage 1 -- this table exists
--     as ready infrastructure for that later, explicitly-approved stage;
--     no write path populates 'direct_purchase' rows yet
--
-- Stage 1 does not populate this table for existing/new retained-withdrawal
-- events either (doing so would require modifying WithdrawalModel.php,
-- which this stage's change-control explicitly excludes -- "Do not modify
-- unrelated: ... withdrawals"). The Stage 1 read-only Shares workspace
-- therefore sources retained-withdrawal figures directly from the existing
-- `withdrawals` table (exactly as ReportModel::getShareReport() and
-- StatementModel already do), and additionally reads any rows that a
-- future stage populates here -- the read layer merges both sources so no
-- second source of truth is created. See ShareModel's class docblock.
--
-- source_reference_type/source_reference_id + the unique key below make
-- any future backfill of historical retained-withdrawal rows into this
-- table naturally idempotent (re-running an insert script would violate
-- the unique key rather than create duplicates).

CREATE TABLE IF NOT EXISTS `share_transactions` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `member_id` INT UNSIGNED NOT NULL,
    `transaction_type` ENUM(
        'retained_withdrawal',
        'direct_purchase',
        'transfer_in',
        'transfer_out',
        'redemption',
        'adjustment'
    ) NOT NULL,
    `transaction_date` DATE NOT NULL,
    `quantity` DECIMAL(15,4) NOT NULL COMMENT 'Fractional shares allowed for retained_withdrawal -- never floored, per approved policy',
    `share_value` DECIMAL(15,2) NOT NULL COMMENT 'Value per share AT this transaction -- never recalculated from a later settings.share_value',
    `amount` DECIMAL(15,2) NOT NULL COMMENT 'Monetary value = quantity * share_value -- the figure JournalService posts/posted',
    `payment_method` ENUM(
        'Cash',
        'Airtel Money',
        'MTN Mobile Money',
        'Bank Transfer',
        'Cheque',
        'Other'
    ) NULL COMMENT 'Mirrors withdrawals.payment_method exactly, for a future direct-purchase write path',
    `reference_number` VARCHAR(20) NULL COMMENT 'e.g. SHR-###### for a future direct purchase; NULL for retained_withdrawal rows, which keep the source withdrawal''s own WDL-###### reference',
    `source_reference_type` VARCHAR(30) NULL COMMENT 'e.g. withdrawal',
    `source_reference_id` INT UNSIGNED NULL COMMENT 'e.g. withdrawals.id, for a retained_withdrawal mirror row',
    `journal_entry_id` INT UNSIGNED NULL COMMENT 'Copied from the already-posted journal entry (e.g. the withdrawal''s) -- NEVER a second posting for the same event',
    `processed_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_share_source` (`source_reference_type`, `source_reference_id`),
    KEY `idx_share_member` (`member_id`),
    KEY `idx_share_transaction_type` (`transaction_type`),
    KEY `idx_share_transaction_date` (`transaction_date`),
    CONSTRAINT `fk_share_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
    CONSTRAINT `fk_share_processed_by` FOREIGN KEY (`processed_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_share_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
