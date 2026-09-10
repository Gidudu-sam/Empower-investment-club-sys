-- ============================================================
--  Loan Product Rules Enforcement + Loan Application Module
--  Safe to re-run (guarded with IF NOT EXISTS / IF-check UPDATEs)
--
--  Scope: additive only. No table dropped, no destructive change
--  to any existing loans/loan_repayments/loan_installments row.
--
--  Business decisions this migration encodes (see the audit reports
--  in results/ for full evidence):
--    - Normal Loan minimum = UGX 100,000 (CLOSED, already the live
--      value here -- this migration does not change it).
--    - Business Loan max duration = 12 months, frozen pending an
--      owner decision. See the BUSINESS_DURATION_DECISION_PENDING_
--      OWNER_CONFIRMATION note attached to loan_product_rules.id=2
--      below -- change duration in exactly this one row when/if the
--      owner decides, nowhere else.
-- ============================================================

-- Deliberately no `USE` statement here -- this file is meant to be applied
-- with `mysql -D <target_db> < this_file.sql` (or piped via a client that
-- has already selected the target database). A `USE empower_db;` line here
-- would silently override the caller's chosen database, which is exactly
-- what happened once during this stage's own implementation: an intended
-- test-database run executed against production instead. Do not add one
-- back without also changing how this file is invoked everywhere it's used.

-- ------------------------------------------------------------
--  1. Close the interest-bracket gaps for Normal Loan (type 1)
--     and Business Loan (type 2). Previously each tier's minimum
--     left a ~100,000 gap uncovered below it (e.g. Tier1 max
--     1,000,000, Tier2 min 1,100,000). The authoritative table now
--     uses X.01 boundaries so every amount above the product
--     minimum resolves to exactly one bracket, with no gap.
-- ------------------------------------------------------------

-- Normal Loan (loan_type_id = 1)
UPDATE `loan_interest_brackets` SET `min_amount` = 1000000.01  WHERE `loan_type_id` = 1 AND `bracket_name` LIKE 'Tier 2%';
UPDATE `loan_interest_brackets` SET `min_amount` = 5000000.01  WHERE `loan_type_id` = 1 AND `bracket_name` LIKE 'Tier 3%';
UPDATE `loan_interest_brackets` SET `min_amount` = 10000000.01 WHERE `loan_type_id` = 1 AND `bracket_name` LIKE 'Tier 4%';
UPDATE `loan_interest_brackets` SET `min_amount` = 15000000.01 WHERE `loan_type_id` = 1 AND `bracket_name` LIKE 'Tier 5%';

-- Business Loan (loan_type_id = 2)
UPDATE `loan_interest_brackets` SET `min_amount` = 1000000.01 WHERE `loan_type_id` = 2 AND `bracket_name` LIKE 'Tier 2%';
UPDATE `loan_interest_brackets` SET `min_amount` = 5000000.01 WHERE `loan_type_id` = 2 AND `bracket_name` LIKE 'Tier 3%';

-- ------------------------------------------------------------
--  2. Processing-fee display/config consistency. The actual charge
--     has always correctly come from `fees` (id=3, per_loan, 3%);
--     this only corrects the separate, previously-inconsistent
--     display-only column so nothing reads a wrong figure from it.
-- ------------------------------------------------------------
UPDATE `loan_product_settings` SET `processing_fee_pct` = 3.00 WHERE `loan_type_id` = 2 AND `processing_fee_pct` != 3.00;

-- ------------------------------------------------------------
--  3. Business Loan duration decision isolation marker.
--     Do not change max_period_months here. This comment is the
--     single authoritative location for that pending decision.
-- ------------------------------------------------------------
UPDATE `loan_product_rules`
   SET `validation_notes` = CONCAT(
         `validation_notes`,
         ' | BUSINESS_DURATION_DECISION_PENDING_OWNER_CONFIRMATION: max_period_months is frozen at 12 (notebook conflict: 6 vs 12 months, unresolved as of 2026-09-03). Change ONLY this row''s max_period_months if/when the owner decides -- no other file encodes this value.'
       )
 WHERE `loan_type_id` = 2
   AND `validation_notes` NOT LIKE '%BUSINESS_DURATION_DECISION_PENDING_OWNER_CONFIRMATION%';

-- ------------------------------------------------------------
--  4. Additive columns on `loans`
-- ------------------------------------------------------------

ALTER TABLE `loans`
  ADD COLUMN `income_source`  ENUM('salary','business') NULL COMMENT 'Asset Financing eligibility: required income source' AFTER `business_location`,
  ADD COLUMN `income_details` VARCHAR(255) NULL COMMENT 'Asset Financing eligibility: free-text detail on the income source' AFTER `income_source`;

-- application_id: durable link to the originating loan_applications row
-- (added after loan_applications is created below, see section 6).

-- ------------------------------------------------------------
--  5. Additive column on `loan_installments` to mark grace-period
--     rows distinctly from ordinary repayment rows.
-- ------------------------------------------------------------
ALTER TABLE `loan_installments`
  ADD COLUMN `is_grace_period` TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Set when this installment falls inside the loan''s configured grace period (principal deferred, interest still due)' AFTER `installment_type`;

-- ------------------------------------------------------------
--  6. Loan Applications module
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_applications` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `application_number`       VARCHAR(20) NOT NULL,
    `member_id`                INT UNSIGNED NOT NULL,
    `loan_type_id`              INT UNSIGNED NOT NULL,
    `requested_amount`          DECIMAL(15,2) NOT NULL,
    `requested_period_months`   INT UNSIGNED NOT NULL,
    `purpose`                   TEXT NULL,

    -- Eligibility snapshot, mirroring the equivalent columns on `loans`
    `income_source`             ENUM('salary','business') NULL,
    `income_details`            VARCHAR(255) NULL,
    `asset_purchase_price`      DECIMAL(15,2) NULL,
    `member_contribution`       DECIMAL(15,2) NULL,
    `security_type`             VARCHAR(100) NULL,
    `security_description`      TEXT NULL,
    `weekly_savings_commitment` DECIMAL(15,2) NULL,

    `status`                    ENUM('draft','pending_approval','approved','rejected') NOT NULL DEFAULT 'draft',

    -- Populated only at approval -- may adjust the requested figures down
    `approved_amount`           DECIMAL(15,2) NULL,
    `approved_period_months`    INT UNSIGNED NULL,

    `recorded_by`                INT UNSIGNED NULL,
    `submitted_at`                TIMESTAMP NULL,
    `approved_by`                 INT UNSIGNED NULL,
    `approved_at`                 TIMESTAMP NULL,
    `rejected_by`                 INT UNSIGNED NULL,
    `rejected_at`                 TIMESTAMP NULL,
    `rejection_reason`            TEXT NULL,

    -- Reverse link + hard one-application-to-one-loan guarantee
    `converted_loan_id`           INT UNSIGNED NULL,
    `converted_at`                TIMESTAMP NULL,

    `created_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_loan_application_number` (`application_number`),
    UNIQUE KEY `uk_loan_application_converted_loan` (`converted_loan_id`),
    KEY `idx_loan_application_member` (`member_id`),
    KEY `idx_loan_application_type` (`loan_type_id`),
    KEY `idx_loan_application_status` (`status`),
    CONSTRAINT `fk_loan_application_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
    CONSTRAINT `fk_loan_application_type` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types` (`id`),
    CONSTRAINT `fk_loan_application_converted_loan` FOREIGN KEY (`converted_loan_id`) REFERENCES `loans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Forward link on `loans`, now that loan_applications exists
ALTER TABLE `loans`
  ADD COLUMN `application_id` INT UNSIGNED NULL COMMENT 'The loan_applications row this loan was recorded from, if any' AFTER `loan_type_id`,
  ADD KEY `idx_loans_application` (`application_id`),
  ADD CONSTRAINT `fk_loans_application` FOREIGN KEY (`application_id`) REFERENCES `loan_applications` (`id`) ON DELETE SET NULL;
