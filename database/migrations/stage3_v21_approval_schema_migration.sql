-- ============================================================
--  Stage 3: V2.1 Multi-Level Approval Architecture Migration
--  TARGET: Production database `empower_db`
--  Date: 2026-09-14
--  
--  This migration deploys the validated V2.1 approval architecture
--  from Stage 2 into production. Schema structure matches the
--  validated test database `empower_approval_test_v21` exactly.
--  
--  IMPORTANT: Do NOT include a USE statement. Pass the target
--  database via CLI: mysql -u root empower_db < thisfile.sql
-- ============================================================

-- ============================================================
--  V2.1 APPROVAL SCHEMA (PRODUCTION)
-- ============================================================

-- 1. approval_policies
CREATE TABLE IF NOT EXISTS `approval_policies` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_version` INT NOT NULL COMMENT 'Policy version number (1, 2, 3...)',
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `effective_from` DATE NOT NULL,
    `effective_to` DATE NULL COMMENT 'NULL = currently active',
    `created_by` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_policy_version` (`transaction_type`, `policy_version`),
    KEY `idx_policy_active` (`transaction_type`, `effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. approval_tiers
CREATE TABLE IF NOT EXISTS `approval_tiers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_id` INT UNSIGNED NOT NULL,
    `tier_number` INT NOT NULL COMMENT '1, 2, 3, 4, 5 (5=officer loan)',
    `tier_name` VARCHAR(50) NOT NULL COMMENT 'e.g. Small Loan, Medium Loan, Officer Loan',
    `min_amount` DECIMAL(15,2) NOT NULL,
    `max_amount` DECIMAL(15,2) NULL COMMENT 'NULL = unlimited',
    `special_rule` VARCHAR(50) NULL COMMENT 'e.g. officer_loan, large_voucher',
    `description` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_tier_policy_number` (`policy_id`, `tier_number`),
    KEY `idx_tier_amount` (`policy_id`, `min_amount`, `max_amount`),
    KEY `idx_tier_special_rule` (`policy_id`, `special_rule`),
    CONSTRAINT `fk_tier_policy` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. approval_tier_slots
CREATE TABLE IF NOT EXISTS `approval_tier_slots` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `tier_id` INT UNSIGNED NOT NULL,
    `slot_number` INT NOT NULL COMMENT '1, 2, 3, 4 (display order)',
    `slot_type` ENUM('mandatory','alternative','sequential') NOT NULL COMMENT 'mandatory=must approve, alternative=one of group, sequential=order matters',
    `slot_group` INT NULL COMMENT 'For alternatives: slots with same group number are alternatives',
    `required_role` VARCHAR(50) NOT NULL COMMENT 'chairman, vice_chairman, secretary, treasurer',
    `display_label` VARCHAR(100) NULL COMMENT 'e.g. "Chairman", "Vice Chairman OR Secretary"',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_slot_tier_number_role` (`tier_id`, `slot_number`, `required_role`),
    KEY `idx_slot_group` (`tier_id`, `slot_group`),
    CONSTRAINT `fk_slot_tier` FOREIGN KEY (`tier_id`) REFERENCES `approval_tiers`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. transaction_approval_rounds
CREATE TABLE IF NOT EXISTS `transaction_approval_rounds` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `transaction_type` ENUM('loan','investment','internal_voucher','member_adjustment','opening_balance','withdrawal','closure') NOT NULL,
    `transaction_id` INT UNSIGNED NOT NULL,
    `round_number` INT NOT NULL DEFAULT 1 COMMENT 'Submission attempt (1=first, 2=after rejection, etc.)',
    
    -- Snapshot of policy at submission time
    `policy_id` INT UNSIGNED NOT NULL COMMENT 'Approval policy version at submission',
    `tier_id` INT UNSIGNED NOT NULL COMMENT 'Tier determined at submission',
    `tier_number` INT NOT NULL,
    `snapshot_amount` DECIMAL(15,2) NOT NULL COMMENT 'Transaction amount at submission (immutable)',
    `snapshot_member_id` INT UNSIGNED NULL COMMENT 'For loans/adjustments: member at submission',
    `snapshot_data` JSON NULL COMMENT 'Other immutable data: accounts, dates, etc.',
    
    -- Approval status
    `approval_status` ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
    `submitted_by` INT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at` TIMESTAMP NULL COMMENT 'When approved/rejected',
    
    -- Special rules
    `excluded_user_ids` JSON NULL COMMENT 'Users excluded from approval (e.g. borrower for officer loans)',
    
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_trans_round` (`transaction_type`, `transaction_id`, `round_number`),
    KEY `idx_approval_status` (`approval_status`),
    KEY `idx_trans_type_id` (`transaction_type`, `transaction_id`),
    KEY `idx_pending` (`approval_status`, `submitted_at`),
    
    CONSTRAINT `fk_trans_round_policy` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies`(`id`),
    CONSTRAINT `fk_trans_round_tier` FOREIGN KEY (`tier_id`) REFERENCES `approval_tiers`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. transaction_approval_slot_instances
CREATE TABLE IF NOT EXISTS `transaction_approval_slot_instances` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `approval_round_id` INT UNSIGNED NOT NULL,
    `slot_id` INT UNSIGNED NOT NULL COMMENT 'References approval_tier_slots',
    `slot_number` INT NOT NULL,
    `slot_type` ENUM('mandatory','alternative','sequential') NOT NULL,
    `slot_group` INT NULL,
    `required_role` VARCHAR(50) NOT NULL,
    `display_label` VARCHAR(100) NULL,
    `slot_status` ENUM('pending','satisfied','not_required') NOT NULL DEFAULT 'pending',
    `satisfied_by_user_id` INT UNSIGNED NULL COMMENT 'User who satisfied this slot',
    `satisfied_at` TIMESTAMP NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY `uk_slot_instance` (`approval_round_id`, `slot_number`, `required_role`),
    KEY `idx_slot_status` (`approval_round_id`, `slot_status`),
    
    CONSTRAINT `fk_slot_inst_round` FOREIGN KEY (`approval_round_id`) REFERENCES `transaction_approval_rounds`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_slot_inst_slot` FOREIGN KEY (`slot_id`) REFERENCES `approval_tier_slots`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. approval_actions (WITH IMMUTABILITY PROTECTION)
CREATE TABLE IF NOT EXISTS `approval_actions` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `approval_round_id` INT UNSIGNED NOT NULL,
    `slot_instance_id` INT UNSIGNED NULL COMMENT 'Which slot this action satisfied (NULL for rejections)',
    `user_id` INT UNSIGNED NOT NULL,
    `user_role` VARCHAR(50) NOT NULL COMMENT 'User role at time of action (chairman, vice_chairman, etc.)',
    `action_type` ENUM('approved','rejected') NOT NULL,
    `action_reason` TEXT NULL COMMENT 'Optional for approve, required for reject',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    
    KEY `idx_action_round` (`approval_round_id`, `created_at`),
    KEY `idx_action_user` (`user_id`),
    KEY `idx_action_slot` (`slot_instance_id`),
    
    -- NO CASCADE DELETE - approval actions are immutable audit evidence
    CONSTRAINT `fk_action_round` FOREIGN KEY (`approval_round_id`) REFERENCES `transaction_approval_rounds`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_action_slot` FOREIGN KEY (`slot_instance_id`) REFERENCES `transaction_approval_slot_instances`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  IMMUTABILITY TRIGGERS (Stage 2.1 Corrections #1 and #2)
-- ============================================================

-- Drop triggers if they exist (for repeat-safe migration)
DROP TRIGGER IF EXISTS `prevent_approval_action_delete`;
DROP TRIGGER IF EXISTS `prevent_approval_action_update`;

-- Trigger #1: Prevent DELETE
CREATE TRIGGER `prevent_approval_action_delete`
BEFORE DELETE ON `approval_actions`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Approval actions are immutable audit records and cannot be deleted';
END;

-- Trigger #2: Prevent UPDATE
CREATE TRIGGER `prevent_approval_action_update`
BEFORE UPDATE ON `approval_actions`
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000'
    SET MESSAGE_TEXT = 'Approval actions are immutable audit records and cannot be modified';
END;

-- ============================================================
--  SEED APPROVAL POLICY V1 FOR LOANS
-- ============================================================

-- Create policy v1 for loans (only if not exists)
INSERT IGNORE INTO `approval_policies` 
(`id`, `policy_version`, `transaction_type`, `effective_from`, `created_by`)
VALUES 
(1, 1, 'loan', '2026-09-15', 1);

-- ============================================================
--  TIER 1: < 1,000,000 (1 approval)
-- ============================================================
INSERT IGNORE INTO `approval_tiers` 
(`id`, `policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `description`)
VALUES 
(1, 1, 1, 'Small Loan', 0.00, 999999.99, 'Loans below UGX 1,000,000 require 1 approval');

-- Tier 1 slots: Chairman OR Vice Chairman (alternative)
INSERT IGNORE INTO `approval_tier_slots` 
(`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`)
VALUES 
(1, 1, 'alternative', 1, 'chairman', 'Chairman'),
(1, 2, 'alternative', 1, 'vice_chairman', 'Vice Chairman');

-- ============================================================
--  TIER 2: >= 1,000,000 AND < 5,000,000 (2 approvals)
-- ============================================================
INSERT IGNORE INTO `approval_tiers` 
(`id`, `policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `description`)
VALUES 
(2, 1, 2, 'Medium Loan', 1000000.00, 4999999.99, 'Loans UGX 1M-5M require Chairman + (Vice Chairman OR Secretary)');

-- Tier 2 slots: Chairman (mandatory) + Vice Chairman OR Secretary (alternative)
INSERT IGNORE INTO `approval_tier_slots` 
(`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`)
VALUES 
(2, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(2, 2, 'alternative', 2, 'vice_chairman', 'Vice Chairman'),
(2, 3, 'alternative', 2, 'secretary', 'Secretary');

-- ============================================================
--  TIER 3: >= 5,000,000 AND < 10,000,000 (3 approvals)
-- ============================================================
INSERT IGNORE INTO `approval_tiers` 
(`id`, `policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `description`)
VALUES 
(3, 1, 3, 'Large Loan', 5000000.00, 9999999.99, 'Loans UGX 5M-10M require Chairman + Vice Chairman + Secretary');

-- Tier 3 slots: Chairman + Vice Chairman + Secretary (all mandatory)
INSERT IGNORE INTO `approval_tier_slots` 
(`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`)
VALUES 
(3, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(3, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(3, 3, 'mandatory', NULL, 'secretary', 'Secretary');

-- ============================================================
--  TIER 4: >= 10,000,000 (4 approvals)
-- ============================================================
INSERT IGNORE INTO `approval_tiers` 
(`id`, `policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `description`)
VALUES 
(4, 1, 4, 'Very Large Loan', 10000000.00, NULL, 'Loans >= UGX 10M require Chairman + Vice Chairman + Secretary + Treasurer');

-- Tier 4 slots: Chairman + Vice Chairman + Secretary + Treasurer (all mandatory)
INSERT IGNORE INTO `approval_tier_slots` 
(`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`)
VALUES 
(4, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(4, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(4, 3, 'mandatory', NULL, 'secretary', 'Secretary'),
(4, 4, 'mandatory', NULL, 'treasurer', 'Treasurer');

-- ============================================================
--  TIER 5: OFFICER LOAN (ANY AMOUNT, 4 APPROVALS)
-- ============================================================
INSERT IGNORE INTO `approval_tiers` 
(`id`, `policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `special_rule`, `description`)
VALUES 
(5, 1, 5, 'Officer Loan', 0.00, NULL, 'officer_loan', 'Loans to club officers require 4 approvals regardless of amount, recipient excluded from approving');

-- Tier 5 slots: Chairman + Vice Chairman + Secretary + Treasurer (all mandatory)
-- NOTE: Recipient must be excluded via excluded_user_ids in transaction_approval_rounds
INSERT IGNORE INTO `approval_tier_slots` 
(`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`)
VALUES 
(5, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(5, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(5, 3, 'mandatory', NULL, 'secretary', 'Secretary'),
(5, 4, 'mandatory', NULL, 'treasurer', 'Treasurer');

-- ============================================================
--  VERIFICATION QUERIES
-- ============================================================
SELECT 'V2.1 Production Schema Migration Complete' AS status;
SELECT 'Policy Count' AS check_type, COUNT(*) AS value FROM approval_policies;
SELECT 'Tier Count' AS check_type, COUNT(*) AS value FROM approval_tiers;
SELECT 'Officer Loan Tier' AS check_type, 
       CONCAT('Tier ', tier_number, ': ', tier_name, ' (special_rule=', IFNULL(special_rule, 'none'), ')') AS value 
FROM approval_tiers WHERE special_rule = 'officer_loan';
SELECT 'Slot Count' AS check_type, COUNT(*) AS value FROM approval_tier_slots;
SELECT 'Triggers' AS check_type, 
       GROUP_CONCAT(TRIGGER_NAME SEPARATOR ', ') AS value 
FROM information_schema.TRIGGERS 
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'approval_actions';

