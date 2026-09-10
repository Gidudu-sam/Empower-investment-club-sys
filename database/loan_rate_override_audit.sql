-- ============================================================
--  Loan Rate Suggestion & Controlled Override Audit Trail
--  Stage 9.1 -- additive only.
--
--  Apply with the target database selected explicitly, e.g.:
--    mysql -u root -D empower_db < database/loan_rate_override_audit.sql
--  Deliberately no `USE` statement -- one already caused an accidental
--  production run during Stage 9's own implementation when a caller's
--  chosen database was silently overridden by a USE statement in the file.
-- ============================================================

ALTER TABLE `loans`
  ADD COLUMN `suggested_interest_rate` DECIMAL(10,6) NULL
      COMMENT 'What LoanProductModel::calculateLoan() returned for this amount/product at save time -- always recorded, even when not overridden'
      AFTER `interest_rate`,
  ADD COLUMN `rate_overridden` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Set when the approved (final) rate differs from the suggested rate'
      AFTER `suggested_interest_rate`,
  ADD COLUMN `rate_override_by` INT UNSIGNED NULL
      COMMENT 'User who recorded/edited the loan when the rate was overridden'
      AFTER `rate_overridden`,
  ADD COLUMN `rate_override_reason` VARCHAR(255) NULL
      COMMENT 'Optional note explaining why the approved rate differs from the suggested one'
      AFTER `rate_override_by`;
