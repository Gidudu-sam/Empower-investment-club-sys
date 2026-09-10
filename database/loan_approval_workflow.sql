-- Stage 8: Loan Approval Workflow (maker-checker for loan origination/disbursement)
-- Additive only. No existing row's data is modified: the 12 existing loans
-- are all status='active' today, which remains a valid enum value, so this
-- migration changes zero existing values -- only widens what NEW rows may
-- become, and adds nullable columns every existing row simply leaves NULL.
--
-- Policy (confirmed by the club before this migration was written):
--   - Approver: Chairman only (mirrors the admin+chairman tier already
--     proven across Internal Vouchers, Member Adjustments, Investments,
--     and Opening Balance Batches).
--   - Disburser: the approver themselves (admin/chairman), not Cashier.
--
-- Deliberately NOT included: no USE statement (see
-- internal_voucher_member_subledger.sql's incident note -- a USE line
-- silently overrides the migration runner's target database argument).

ALTER TABLE `loans`
  MODIFY COLUMN `status` ENUM('draft','pending_approval','pending','active','approved','completed','overdue','defaulted','rejected')
      NOT NULL DEFAULT 'draft'
      COMMENT 'pending is legacy/unused, kept for backward compatibility. draft/pending_approval/approved/rejected added in Stage 8.',
  ADD COLUMN `submitted_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Stage 8: when submitted for approval' AFTER `application_date`,
  ADD COLUMN `approved_by` INT UNSIGNED NULL COMMENT 'Stage 8: approver user id' AFTER `date_approved`,
  ADD COLUMN `approved_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Stage 8: when approved' AFTER `approved_by`,
  ADD COLUMN `rejected_by` INT UNSIGNED NULL COMMENT 'Stage 8: rejecter user id' AFTER `approved_at`,
  ADD COLUMN `rejected_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Stage 8: when rejected' AFTER `rejected_by`,
  ADD COLUMN `rejection_reason` TEXT NULL COMMENT 'Stage 8: required reason on reject' AFTER `rejected_at`,
  ADD COLUMN `disbursed_by` INT UNSIGNED NULL COMMENT 'Stage 8: who triggered disburse()' AFTER `disbursement_method`,
  ADD COLUMN `disbursed_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Stage 8: when disburse() ran' AFTER `disbursed_by`,
  ADD KEY `fk_loans_approved_by` (`approved_by`),
  ADD KEY `fk_loans_rejected_by` (`rejected_by`),
  ADD KEY `fk_loans_disbursed_by` (`disbursed_by`),
  ADD CONSTRAINT `fk_loans_approved_by`  FOREIGN KEY (`approved_by`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_loans_rejected_by`  FOREIGN KEY (`rejected_by`)  REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_loans_disbursed_by` FOREIGN KEY (`disbursed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
