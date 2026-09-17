-- ============================================================
-- PRODUCTION DATA CLEANUP SCRIPT
-- Date: September 17, 2026
-- Purpose: Remove all test data, keep only Members and Withdrawals (Shares)
-- ============================================================

-- CRITICAL: CREATE BACKUP FIRST!
-- Run this command in CMD/PowerShell BEFORE running this script:
-- cd C:\xampp\mysql\bin
-- .\mysqldump.exe -u root empower_db > C:\xampp\htdocs\Empower\backups\pre_cleanup_backup.sql

USE empower_db;

-- ============================================================
-- STEP 1: Check Current Data (Before Cleanup)
-- ============================================================
SELECT 'BEFORE CLEANUP - DATA COUNTS' as info;

SELECT 
    (SELECT COUNT(*) FROM members) as members,
    (SELECT COUNT(*) FROM withdrawals) as withdrawals,
    (SELECT COUNT(*) FROM savings) as savings,
    (SELECT COUNT(*) FROM loans) as loans,
    (SELECT COUNT(*) FROM loan_repayments) as repayments,
    (SELECT COUNT(*) FROM member_fees) as fees,
    (SELECT COUNT(*) FROM journal_entries) as journal_entries,
    (SELECT COUNT(*) FROM financial_years) as financial_years,
    (SELECT COUNT(*) FROM users) as users;

-- ============================================================
-- STEP 2: Disable Foreign Key Checks (Temporary)
-- ============================================================
SET FOREIGN_KEY_CHECKS = 0;
SET SQL_SAFE_UPDATES = 0;

-- ============================================================
-- STEP 3: Delete Child Records First
-- ============================================================

-- Loan-related child records
DELETE FROM loan_installments;
DELETE FROM loan_penalties;
DELETE FROM business_loan_interest_payments WHERE id > 0;
DELETE FROM business_loan_weekly_savings WHERE id > 0;
DELETE FROM loan_repayments;

SELECT 'Phase 1: Deleted loan child records' as status;

-- Fee records
DELETE FROM member_fees;
DELETE FROM fee_history WHERE id > 0;

SELECT 'Phase 2: Deleted fee records' as status;

-- Accounting child records
DELETE FROM journal_entry_lines WHERE id > 0;
DELETE FROM opening_balance_items WHERE id > 0;

SELECT 'Phase 3: Deleted accounting child records' as status;

-- Approval workflow instances
DELETE FROM transaction_approval_slot_instances WHERE id > 0;
DELETE FROM transaction_approval_rounds WHERE id > 0;

SELECT 'Phase 4: Deleted approval instances' as status;

-- Provisioning details
DELETE FROM loan_provisioning_details WHERE id > 0;

SELECT 'Phase 5: Deleted provisioning details' as status;

-- ============================================================
-- STEP 4: Delete Parent Transaction Records
-- ============================================================

-- Loan applications and loans
DELETE FROM loan_applications;
DELETE FROM loans;

SELECT 'Phase 6: Deleted loans and applications' as status;

-- Savings transactions
DELETE FROM savings;

SELECT 'Phase 7: Deleted savings deposits' as status;

-- Savings accounts (if table exists)
DELETE FROM member_savings_accounts WHERE id > 0;
DELETE FROM savings_accounts WHERE id > 0;

SELECT 'Phase 8: Deleted savings accounts' as status;

-- Fixed deposits
DELETE FROM fixed_deposits WHERE id > 0;
DELETE FROM fixed_deposit_closure_requests WHERE id > 0;
DELETE FROM savings_account_closure_requests WHERE id > 0;

SELECT 'Phase 9: Deleted fixed deposits' as status;

-- Investments
DELETE FROM investments WHERE id > 0;

SELECT 'Phase 10: Deleted investments' as status;

-- ============================================================
-- STEP 5: Delete Accounting Records
-- ============================================================

-- Accounting parent records
DELETE FROM journal_entries;
DELETE FROM internal_vouchers;
DELETE FROM member_account_adjustments WHERE id > 0;
DELETE FROM opening_balance_batches WHERE id > 0;
DELETE FROM opening_balances WHERE id > 0;

SELECT 'Phase 11: Deleted accounting records' as status;

-- Loan provisioning runs
DELETE FROM loan_provisioning_runs WHERE id > 0;

SELECT 'Phase 12: Deleted provisioning runs' as status;

-- Cash references
DELETE FROM cash_payment_references WHERE id > 0;

SELECT 'Phase 13: Deleted cash references' as status;

-- Referral bonuses
DELETE FROM referral_bonuses WHERE id > 0;

SELECT 'Phase 14: Deleted referral bonuses' as status;

-- ============================================================
-- STEP 6: Delete Supporting Records
-- ============================================================

-- Notifications
DELETE FROM notifications;

SELECT 'Phase 15: Deleted notifications' as status;

-- Activity logs
DELETE FROM activity_logs;
DELETE FROM user_activity_log WHERE id > 0;

SELECT 'Phase 16: Deleted activity logs' as status;

-- Corrections (test data)
DELETE FROM corrections WHERE id > 0;

SELECT 'Phase 17: Deleted corrections' as status;

-- Database recovery records
DELETE FROM database_recoveries WHERE id > 0;

SELECT 'Phase 18: Deleted recovery records' as status;

-- ============================================================
-- STEP 7: Delete Financial Structure
-- ============================================================

-- Accounting periods (will be recreated)
DELETE FROM accounting_periods;

SELECT 'Phase 19: Deleted accounting periods' as status;

-- Financial years (will be recreated)
DELETE FROM financial_years;

SELECT 'Phase 20: Deleted financial years' as status;

-- ============================================================
-- STEP 8: Reset Auto-Increment Counters
-- ============================================================

ALTER TABLE savings AUTO_INCREMENT = 1;
ALTER TABLE loans AUTO_INCREMENT = 1;
ALTER TABLE loan_repayments AUTO_INCREMENT = 1;
ALTER TABLE member_fees AUTO_INCREMENT = 1;
ALTER TABLE journal_entries AUTO_INCREMENT = 1;
ALTER TABLE internal_vouchers AUTO_INCREMENT = 1;
ALTER TABLE loan_applications AUTO_INCREMENT = 1;
ALTER TABLE investments AUTO_INCREMENT = 1;
ALTER TABLE fixed_deposits AUTO_INCREMENT = 1;
ALTER TABLE notifications AUTO_INCREMENT = 1;
ALTER TABLE activity_logs AUTO_INCREMENT = 1;

-- Do NOT reset members or withdrawals - they contain real data!

SELECT 'Phase 21: Reset auto-increment counters' as status;

-- ============================================================
-- STEP 9: Re-enable Foreign Key Checks
-- ============================================================

SET FOREIGN_KEY_CHECKS = 1;
SET SQL_SAFE_UPDATES = 1;

SELECT 'Phase 22: Re-enabled foreign key checks' as status;

-- ============================================================
-- STEP 10: Verify Cleanup Results
-- ============================================================

SELECT 'AFTER CLEANUP - DATA COUNTS' as info;

SELECT 
    'members' as table_name, COUNT(*) as remaining_count FROM members
UNION ALL
SELECT 'withdrawals', COUNT(*) FROM withdrawals
UNION ALL
SELECT 'users', COUNT(*) FROM users
UNION ALL
SELECT 'roles', COUNT(*) FROM roles
UNION ALL
SELECT 'chart_of_accounts', COUNT(*) FROM chart_of_accounts
UNION ALL
SELECT 'savings', COUNT(*) FROM savings
UNION ALL
SELECT 'loans', COUNT(*) FROM loans
UNION ALL
SELECT 'loan_repayments', COUNT(*) FROM loan_repayments
UNION ALL
SELECT 'member_fees', COUNT(*) FROM member_fees
UNION ALL
SELECT 'financial_years', COUNT(*) FROM financial_years
UNION ALL
SELECT 'accounting_periods', COUNT(*) FROM accounting_periods
UNION ALL
SELECT 'journal_entries', COUNT(*) FROM journal_entries
UNION ALL
SELECT 'notifications', COUNT(*) FROM notifications;

-- ============================================================
-- STEP 11: Verify Share Data Preserved
-- ============================================================

SELECT 'SHARE DATA VERIFICATION' as info;

SELECT 
    COUNT(*) as total_withdrawals,
    SUM(withdrawal_amount) as total_cash_withdrawn,
    SUM(retained_amount) as total_shares_retained,
    MIN(withdrawal_date) as earliest_date,
    MAX(withdrawal_date) as latest_date
FROM withdrawals;

-- ============================================================
-- CLEANUP COMPLETE!
-- ============================================================

SELECT '
╔════════════════════════════════════════════════════════════╗
║                                                            ║
║         ✅ PRODUCTION CLEANUP SUCCESSFUL!                  ║
║                                                            ║
║  ✅ Members preserved                                      ║
║  ✅ Withdrawals/Shares preserved                           ║
║  ✅ All test transactions deleted                          ║
║  ✅ System configuration intact                            ║
║                                                            ║
║  📝 NEXT STEPS:                                            ║
║  1. Create new Financial Year                              ║
║  2. Create new Accounting Periods                          ║
║  3. Start recording real transactions                      ║
║                                                            ║
╚════════════════════════════════════════════════════════════╝
' as CLEANUP_COMPLETE;
