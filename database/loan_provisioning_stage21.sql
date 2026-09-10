-- ============================================================
--  Loan-Loss Provisioning Module (Stage 21)
--  Engine: InnoDB   Charset: utf8mb4
--
--  Four new tables implementing the management-approved policy
--  PROV-001 (results/stage_20B_completed_management_decision_sheet.md).
--  No existing table, row, or journal entry is touched. The historical
--  broken `loan_provisioning_buckets` table remains untouched, unused,
--  and is NOT referenced by any FK or code here -- it is forensic
--  evidence only, per the approved policy's own explicit rule.
--
--  Design principle (Stage 21 gate finding, mandatory): a finalized
--  run must FREEZE its calculated exposure/aging inputs in
--  loan_provisioning_run_details, never re-derive them from live
--  loan_repayments/loan_installments later -- because RepaymentModel::
--  delete() hard-deletes loan_repayments rows with no dated reversal
--  trail. The run_details table is therefore the historical source of
--  truth for a finalized run, not a cache.
--
--  Authorization note: the policy's approved_by fields are stored as
--  free text, NOT a foreign key to `users`, because the production
--  `users` table has no active account matching the supplied name
--  "Gidudu Samuel" with role "Loans Officer and System Administrator"
--  (the closest match, id=299 "Gidudu Samuel William", is role=member
--  and is_active=0). Recording as free text avoids misattributing the
--  approval to an unrelated or inactive system account.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
-- 1. Policy header (versioned)
-- ------------------------------------------------------------
CREATE TABLE `loan_provisioning_policies` (
    `id`                        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `version`                   VARCHAR(20) NOT NULL COMMENT 'e.g. PROV-001',
    `status`                    ENUM('draft','active','superseded') NOT NULL DEFAULT 'draft',
    `effective_date`            DATE NOT NULL,
    `exposure_basis`            VARCHAR(50) NOT NULL DEFAULT 'principal_only' COMMENT 'Fixed to principal_only per PROV-001, stored for auditability, not a live switch',
    `frequency`                 VARCHAR(50) NOT NULL DEFAULT 'period_end',
    `as_of_date_method`         VARCHAR(50) NOT NULL DEFAULT 'accounting_period_end',
    `grace_period_rule`         VARCHAR(100) NOT NULL DEFAULT 'due_date_governs' COMMENT 'PROV-001 Decision #16, Option A',
    `missed_overdue_rule`       VARCHAR(100) NOT NULL DEFAULT 'identical_treatment' COMMENT 'PROV-001 Decision #20, Option A',
    `approved_by_name`          VARCHAR(150) NOT NULL COMMENT 'Recorded verbatim as supplied -- not a users FK, see file header',
    `approved_by_role`          VARCHAR(150) NOT NULL COMMENT 'Recorded verbatim as supplied',
    `authorization_reference`   TEXT NOT NULL COMMENT 'Recorded verbatim as supplied',
    `approval_date`             DATE NOT NULL,
    `notes`                     TEXT NULL,
    `created_by`                INT UNSIGNED NULL COMMENT 'Technical actor who created this row, if a live user session, NULL for this initial migration seed',
    `created_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_policy_version` (`version`),
    KEY `idx_policy_status` (`status`),
    CONSTRAINT `fk_provpol_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Policy bands (child of policy, versioned with it)
-- ------------------------------------------------------------
CREATE TABLE `loan_provisioning_policy_bands` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_id`     INT UNSIGNED NOT NULL,
    `bucket_name`   VARCHAR(50) NOT NULL,
    `min_days`      INT UNSIGNED NOT NULL,
    `max_days`      INT UNSIGNED NULL COMMENT 'NULL = no upper bound (181+ band)',
    `rate`          DECIMAL(6,3) NOT NULL COMMENT 'Percentage, e.g. 5.000 for 5%',
    `sort_order`    INT UNSIGNED NOT NULL,
    `created_at`    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_policy_band_order` (`policy_id`, `sort_order`),
    CONSTRAINT `fk_provband_policy` FOREIGN KEY (`policy_id`) REFERENCES `loan_provisioning_policies`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 3. Provisioning run header
-- ------------------------------------------------------------
CREATE TABLE `loan_provisioning_runs` (
    `id`                            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `run_number`                    VARCHAR(20) NOT NULL,
    `policy_id`                     INT UNSIGNED NOT NULL,
    `accounting_period_id`          INT UNSIGNED NOT NULL,
    `as_of_date`                    DATE NOT NULL,
    `status`                        ENUM('draft','calculated','reviewed','finalized') NOT NULL DEFAULT 'draft',
    `total_exposure`                DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_required_provision`      DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_previous_provision`      DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `total_delta`                   DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    `loan_count`                    INT UNSIGNED NOT NULL DEFAULT 0,
    `journal_entry_id`              INT UNSIGNED NULL,
    `corrects_run_id`               INT UNSIGNED NULL COMMENT 'Set when this run is a forward correction of an earlier finalized run -- never edits that run in place',
    `calculated_by`                 INT UNSIGNED NULL,
    `calculated_at`                 TIMESTAMP NULL DEFAULT NULL,
    `reviewed_by`                   INT UNSIGNED NULL,
    `reviewed_at`                   TIMESTAMP NULL DEFAULT NULL,
    `finalized_by`                  INT UNSIGNED NULL,
    `finalized_at`                  TIMESTAMP NULL DEFAULT NULL,
    `created_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_provrun_number` (`run_number`),
    KEY `idx_provrun_status` (`status`),
    KEY `idx_provrun_period` (`accounting_period_id`),
    KEY `idx_provrun_asof` (`as_of_date`),
    CONSTRAINT `fk_provrun_policy` FOREIGN KEY (`policy_id`) REFERENCES `loan_provisioning_policies`(`id`),
    CONSTRAINT `fk_provrun_period` FOREIGN KEY (`accounting_period_id`) REFERENCES `accounting_periods`(`id`),
    CONSTRAINT `fk_provrun_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_provrun_corrects` FOREIGN KEY (`corrects_run_id`) REFERENCES `loan_provisioning_runs`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_provrun_calc_by` FOREIGN KEY (`calculated_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_provrun_rev_by` FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_provrun_fin_by` FOREIGN KEY (`finalized_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Numbering -- reuse the existing row-locked sequence mechanism (Stage 21 §19).
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('PROVRUN', 0);

-- ------------------------------------------------------------
-- 4. Provisioning run detail (the frozen, per-loan snapshot)
-- ------------------------------------------------------------
CREATE TABLE `loan_provisioning_run_details` (
    `id`                                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `run_id`                            INT UNSIGNED NOT NULL,
    `loan_id`                           INT UNSIGNED NOT NULL,
    `member_id`                         INT UNSIGNED NOT NULL,
    `original_principal`                DECIMAL(15,2) NOT NULL,
    `qualifying_principal_paid`         DECIMAL(15,2) NOT NULL,
    `unpaid_principal_exposure`         DECIMAL(15,2) NOT NULL,
    `oldest_qualifying_installment_id`  BIGINT UNSIGNED NULL,
    `oldest_qualifying_due_date`        DATE NULL,
    `days_past_due`                     INT NOT NULL DEFAULT 0,
    `bucket_name`                       VARCHAR(50) NOT NULL,
    `bucket_min_days`                   INT UNSIGNED NOT NULL,
    `bucket_max_days`                   INT UNSIGNED NULL,
    `applied_rate`                      DECIMAL(6,3) NOT NULL,
    `required_provision`                DECIMAL(15,2) NOT NULL,
    `previous_provision`                DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `delta`                             DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    `exclusion_reason`                  VARCHAR(255) NULL COMMENT 'Set (and the loan excluded from totals) when data-quality checks fail -- e.g. negative principal, missing due date',
    `created_at`                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_provdetail_run_loan` (`run_id`, `loan_id`),
    KEY `idx_provdetail_loan` (`loan_id`),
    KEY `idx_provdetail_bucket` (`bucket_name`),
    CONSTRAINT `fk_provdetail_run` FOREIGN KEY (`run_id`) REFERENCES `loan_provisioning_runs`(`id`),
    CONSTRAINT `fk_provdetail_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`),
    CONSTRAINT `fk_provdetail_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`),
    CONSTRAINT `fk_provdetail_installment` FOREIGN KEY (`oldest_qualifying_installment_id`) REFERENCES `loan_installments`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 5. Seed the approved PROV-001 policy and its bands.
--    This is the approved POLICY CONFIGURATION being deployed, not a
--    test transaction -- no loan, repayment, or provisioning RUN data
--    is inserted here. approved_by_* fields are recorded verbatim from
--    results/stage_20B_completed_management_decision_sheet.md.
-- ------------------------------------------------------------
INSERT INTO `loan_provisioning_policies`
    (`version`, `status`, `effective_date`, `exposure_basis`, `frequency`, `as_of_date_method`,
     `grace_period_rule`, `missed_overdue_rule`,
     `approved_by_name`, `approved_by_role`, `authorization_reference`, `approval_date`, `notes`)
VALUES
    ('PROV-001', 'active', '2026-09-09', 'principal_only', 'period_end', 'accounting_period_end',
     'due_date_governs', 'identical_treatment',
     'Gidudu Samuel', 'Loans Officer and System Administrator',
     'Explicit management authorization provided in this conversation on 2026-09-09.',
     '2026-09-09',
     'See results/stage_21A_management_authorization_closure.md for a recorded governance observation regarding this role and the authorization matrix proposed in Stage 20.');

INSERT INTO `loan_provisioning_policy_bands` (`policy_id`, `bucket_name`, `min_days`, `max_days`, `rate`, `sort_order`)
SELECT id, 'Current', 0, 0, 0.000, 1 FROM `loan_provisioning_policies` WHERE `version`='PROV-001'
UNION ALL
SELECT id, '1-30 days', 1, 30, 1.000, 2 FROM `loan_provisioning_policies` WHERE `version`='PROV-001'
UNION ALL
SELECT id, '31-90 days', 31, 90, 5.000, 3 FROM `loan_provisioning_policies` WHERE `version`='PROV-001'
UNION ALL
SELECT id, '91-180 days', 91, 180, 25.000, 4 FROM `loan_provisioning_policies` WHERE `version`='PROV-001'
UNION ALL
SELECT id, '181+ days', 181, NULL, 100.000, 5 FROM `loan_provisioning_policies` WHERE `version`='PROV-001';
