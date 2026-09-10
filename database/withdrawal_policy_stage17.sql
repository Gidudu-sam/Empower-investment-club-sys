-- ============================================================
--  STAGE 17 PART E-0 — Configurable Savings Withdrawal Policy
--  Creates ONE new table. No existing table is altered. No
--  financial transaction table is touched. See
--  results/stage17_withdrawal_policy_evidence/03_policy_schema.txt
--  for the field-by-field justification.
--
--  account_type mirrors member_savings_accounts.account_type's
--  existing ENUM exactly -- no separate account_types lookup table
--  exists in this codebase, so none is invented here.
--
--  Effective-dated history, not overwrite-in-place: a row governs
--  transactions from effective_from up to (and including)
--  effective_to, or indefinitely if effective_to IS NULL. Changing
--  policy means closing out the current row's effective_to and
--  inserting a new row -- never mutating an already-effective row's
--  percentages (WithdrawalPolicyModel enforces this).
-- ============================================================
USE `empower_db`;

CREATE TABLE IF NOT EXISTS `savings_withdrawal_policies` (
    `id`                          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_type`                ENUM('compulsory','voluntary','joint','corporate') NOT NULL,
    `withdrawal_enabled`          TINYINT(1) NOT NULL DEFAULT 1,
    `maximum_withdrawal_percent`  DECIMAL(5,2) NOT NULL,
    `share_conversion_percent`    DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `frequency`                   ENUM('any_time','once_per_financial_year','monthly','quarterly','half_yearly','custom') NOT NULL DEFAULT 'any_time',
    `minimum_balance`             DECIMAL(15,2) DEFAULT NULL,
    `effective_from`              DATE NOT NULL,
    `effective_to`                DATE DEFAULT NULL,
    `status`                      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_by`                  INT UNSIGNED DEFAULT NULL,
    `updated_by`                  INT UNSIGNED DEFAULT NULL,
    `created_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_swp_account_type` (`account_type`),
    KEY `idx_swp_effective` (`account_type`, `effective_from`, `effective_to`),
    KEY `idx_swp_status` (`status`),
    CONSTRAINT `fk_swp_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_swp_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
    CONSTRAINT `chk_swp_max_pct` CHECK (`maximum_withdrawal_percent` >= 0 AND `maximum_withdrawal_percent` <= 100),
    CONSTRAINT `chk_swp_share_pct` CHECK (`share_conversion_percent` >= 0 AND `share_conversion_percent` <= 100),
    CONSTRAINT `chk_swp_combined_pct` CHECK (`maximum_withdrawal_percent` + `share_conversion_percent` <= 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Default policies for the two currently-approved account types
-- only (Compulsory, Voluntary) -- per §14, Joint/Corporate are
-- deliberately left WITHOUT an active policy (no approved business
-- rule exists for them), so getActivePolicy() returns "not
-- configured" for those types rather than silently allowing
-- withdrawals under an invented rule.
-- Idempotent: only inserts if no row already exists for the type.
-- ------------------------------------------------------------
INSERT INTO `savings_withdrawal_policies`
    (`account_type`, `withdrawal_enabled`, `maximum_withdrawal_percent`, `share_conversion_percent`, `frequency`, `minimum_balance`, `effective_from`, `status`)
SELECT 'compulsory', 1, 50.00, 50.00, 'once_per_financial_year', NULL, '2026-01-01', 'active'
WHERE NOT EXISTS (SELECT 1 FROM `savings_withdrawal_policies` WHERE `account_type` = 'compulsory');

INSERT INTO `savings_withdrawal_policies`
    (`account_type`, `withdrawal_enabled`, `maximum_withdrawal_percent`, `share_conversion_percent`, `frequency`, `minimum_balance`, `effective_from`, `status`)
SELECT 'voluntary', 1, 100.00, 0.00, 'any_time', NULL, '2026-01-01', 'active'
WHERE NOT EXISTS (SELECT 1 FROM `savings_withdrawal_policies` WHERE `account_type` = 'voluntary');
