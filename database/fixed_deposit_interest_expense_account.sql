-- Stage 11-A — Fixed Deposit Interest Expense Chart of Accounts resolution.
--
-- Forensic audit (results/stage11a_fd_interest_expense_coa_audit_report.md)
-- found no existing account suitable for interest paid to Fixed Deposit
-- holders -- only two unrelated INCOME accounts (4035 Loan Interest
-- Income, 4070 Bank Interest Received) contain the word "interest", and
-- the expense side (5010-5350) has no interest/finance-cost account or
-- subtype at all. A new account is required (Decision Case B).
--
-- Code 5360 is the next free code in the existing sequential
-- expense-account convention (5010, 5020, ... 5340, 5350 -- each new
-- expense account takes the next multiple of 10; 5350 "Referral
-- Commissions & Bonuses" was the most recent). subtype/normal_balance
-- are set explicitly to match the dominant convention used by every
-- other expense account (37 of 38 existing rows), not the one
-- unexplained blank-subtype exception at 5350.
--
-- Purely additive, idempotent (safe to run more than once), no existing
-- account or historical journal is touched. No hardcoded `USE` statement
-- -- the target database is selected by the invoking `mysql` command,
-- matching FD-2's own (correct) migration convention.

INSERT INTO `accounts` (`code`, `name`, `type`, `subtype`, `normal_balance`, `parent_id`, `is_system`, `is_active`, `requires_subledger`, `description`)
SELECT '5360', 'Fixed Deposit Interest Expense', 'expense', 'operating_expense', 'debit', NULL, 0, 1, 0,
       'Interest paid/accrued to members on matured Fixed Deposit accounts at closure payout (Stage FD-2).'
WHERE NOT EXISTS (SELECT 1 FROM `accounts` WHERE `code` = '5360')
  AND NOT EXISTS (SELECT 1 FROM `accounts` WHERE `name` = 'Fixed Deposit Interest Expense');
