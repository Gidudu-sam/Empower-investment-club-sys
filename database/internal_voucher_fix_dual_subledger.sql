-- ============================================================
-- INTERNAL VOUCHER DUAL SUBLEDGER SCHEMA FIX
-- ============================================================
-- Purpose: Add contra member fields to internal_vouchers table
--          to support transfers between TWO different members' accounts
-- 
-- Example: Member A's Compulsory → Member B's Voluntary
--   - member_id = A, savings_account_id = Compulsory
--   - contra_member_id = B, contra_savings_account_id = Voluntary
-- ============================================================

USE empower_db;

-- Add contra_member_id column (for the OTHER member in cross-member transfers)
ALTER TABLE internal_vouchers 
ADD COLUMN contra_member_id INT UNSIGNED NULL 
COMMENT 'ID of the contra-party member (for cross-member transfers)' 
AFTER savings_id;

-- Add contra_savings_account_id column
ALTER TABLE internal_vouchers 
ADD COLUMN contra_savings_account_id INT UNSIGNED NULL 
COMMENT 'ID of contra-party savings account type' 
AFTER contra_member_id;

-- Add contra_savings_id column
ALTER TABLE internal_vouchers 
ADD COLUMN contra_savings_id INT UNSIGNED NULL 
COMMENT 'ID of contra-party member_savings record' 
AFTER contra_savings_account_id;

-- Add contra_balance_before column
ALTER TABLE internal_vouchers 
ADD COLUMN contra_balance_before DECIMAL(15,2) NULL DEFAULT 0.00 
COMMENT 'Contra-party balance before posting' 
AFTER balance_after;

-- Add contra_balance_after column
ALTER TABLE internal_vouchers 
ADD COLUMN contra_balance_after DECIMAL(15,2) NULL DEFAULT 0.00 
COMMENT 'Contra-party balance after posting' 
AFTER contra_balance_before;

-- Add foreign key for contra_member_id
ALTER TABLE internal_vouchers 
ADD CONSTRAINT fk_internal_vouchers_contra_member 
FOREIGN KEY (contra_member_id) REFERENCES members(id) ON DELETE RESTRICT;

-- Add foreign key for contra_savings_account_id
ALTER TABLE internal_vouchers 
ADD CONSTRAINT fk_internal_vouchers_contra_savings_account 
FOREIGN KEY (contra_savings_account_id) REFERENCES member_savings_accounts(id) ON DELETE RESTRICT;

-- Add foreign key for contra_savings_id
ALTER TABLE internal_vouchers 
ADD CONSTRAINT fk_internal_vouchers_contra_savings 
FOREIGN KEY (contra_savings_id) REFERENCES savings(id) ON DELETE RESTRICT;
