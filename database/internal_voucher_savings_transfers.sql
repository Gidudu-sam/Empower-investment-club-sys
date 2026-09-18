-- ============================================================
--  Internal Voucher Enhancement: Savings Account Transfers
--  
--  Adds support for savings account-to-account transfers where
--  a member can transfer between their own savings accounts
--  (e.g., Compulsory → Voluntary)
--
--  Additive only - no existing data modified
-- ============================================================

USE `empower_db`;

-- Add transfer transaction types to savings table
ALTER TABLE `savings`
    MODIFY COLUMN `transaction_type` 
    ENUM('opening_balance','deposit','withdrawal','adjustment','transfer_in','transfer_out') 
    NOT NULL;

-- Add destination savings account fields for account-to-account transfers
ALTER TABLE `internal_vouchers`
    ADD COLUMN IF NOT EXISTS `destination_member_id` INT UNSIGNED NULL 
        AFTER `savings_account_id`
        COMMENT 'For savings account transfers: destination member (must match source member_id)';

ALTER TABLE `internal_vouchers`
    ADD COLUMN IF NOT EXISTS `destination_savings_account_id` INT UNSIGNED NULL 
        AFTER `destination_member_id`
        COMMENT 'For savings account transfers: destination account';

ALTER TABLE `internal_vouchers`
    ADD COLUMN IF NOT EXISTS `destination_savings_id` INT UNSIGNED NULL 
        AFTER `destination_savings_account_id`
        COMMENT 'Resulting savings transaction ID for destination account';

-- Add foreign keys
ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT IF NOT EXISTS `fk_voucher_dest_member`
    FOREIGN KEY (`destination_member_id`) REFERENCES `members`(`id`) ON DELETE RESTRICT;

ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT IF NOT EXISTS `fk_voucher_dest_savings_account`
    FOREIGN KEY (`destination_savings_account_id`) REFERENCES `member_savings_accounts`(`id`) ON DELETE RESTRICT;

ALTER TABLE `internal_vouchers`
    ADD CONSTRAINT IF NOT EXISTS `fk_voucher_dest_savings`
    FOREIGN KEY (`destination_savings_id`) REFERENCES `savings`(`id`) ON DELETE SET NULL;

-- Add check constraint: if destination exists, both members must match
-- Note: MySQL 8.0.16+ supports CHECK constraints
-- ALTER TABLE `internal_vouchers`
--     ADD CONSTRAINT `chk_voucher_same_member_transfer`
--     CHECK (
--         (destination_member_id IS NULL) OR 
--         (member_id IS NULL) OR 
--         (member_id = destination_member_id)
--     );
-- This constraint is enforced at application level for compatibility

-- ============================================================
--  VERIFICATION QUERIES
-- ============================================================

-- Check new columns exist:
-- DESCRIBE internal_vouchers;

-- Check constraints:
-- SELECT * FROM information_schema.TABLE_CONSTRAINTS 
-- WHERE table_name = 'internal_vouchers' AND table_schema = 'empower_db';
