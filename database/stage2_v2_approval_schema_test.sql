-- ============================================================
--  Stage 2: V2 Multi-Approval Architecture — Schema Validation
--  DISPOSABLE TEST DATABASE ONLY
--  Database: empower_approval_test
--  Date: 2026-09-14
-- ============================================================

-- 1. Create test database
CREATE DATABASE IF NOT EXISTS `empower_approval_test` 
CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE `empower_approval_test`;

-- ============================================================
--  V2 APPROVAL SCHEMA
-- ============================================================

-- 1. approval_policies
CREATE TABLE `approval_policies` (
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
CREATE TABLE `approval_tiers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `policy_id` INT UNSIGNED NOT NULL,
    `tier_number` INT NOT NULL COMMENT '1, 2, 3, 4',
    `tier_name` VARCHAR(50) NOT NULL COMMENT 'e.g. Small Loan, Medium Loan',
    `min_amount` DECIMAL(15,2) NOT NULL,
    `max_amount` DECIMAL(15,2) NULL COMMENT 'NULL = unlimited',
    `special_rule` VARCHAR(50) NULL COMMENT 'e.g. officer_loan, large_voucher',
    `description` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_tier_policy_number` (`policy_id`, `tier_number`),
    KEY `idx_tier_amount` (`policy_id`, `min_amount`, `max_amount`),
    CONSTRAINT `fk_tier_policy` FOREIGN KEY (`policy_id`) REFERENCES `approval_policies`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. approval_tier_slots
CREATE TABLE `approval_tier_slots` (
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
CREATE TABLE `transaction_approval_rounds` (
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
CREATE TABLE `transaction_approval_slot_instances` (
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

-- 6. approval_actions
CREATE TABLE `approval_actions` (
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
--  TEST USERS TABLE (Minimal for testing)
-- ============================================================
CREATE TABLE `test_users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) NOT NULL,
    `full_name` VARCHAR(100) NOT NULL,
    `role` VARCHAR(50) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  SEED TEST USERS
-- ============================================================
INSERT INTO `test_users` (`id`, `username`, `full_name`, `role`, `is_active`) VALUES
(1, 'chairman', 'John Doe (Chairman)', 'chairman', 1),
(2, 'vice_chairman', 'Mary Smith (Vice Chairman)', 'vice_chairman', 1),
(3, 'secretary', 'Peter Jones (Secretary)', 'secretary', 1),
(4, 'treasurer', 'Sarah Williams (Treasurer)', 'treasurer', 1),
(5, 'loans_officer', 'David Brown (Loans Officer)', 'loans_officer', 1),
(6, 'secretary2', 'Alice Johnson (Secretary 2)', 'secretary', 1),
(7, 'admin', 'Bob Wilson (Admin)', 'admin', 1),
(8, 'cashier', 'Carol Davis (Cashier)', 'cashier', 1);

-- ============================================================
--  SEED POLICY VERSION 1 — LOANS
-- ============================================================
INSERT INTO `approval_policies` (`policy_version`, `transaction_type`, `effective_from`, `created_by`) 
VALUES (1, 'loan', '2026-09-15', 1);

SET @policy_id = LAST_INSERT_ID();

-- Create tiers
INSERT INTO `approval_tiers` (`policy_id`, `tier_number`, `tier_name`, `min_amount`, `max_amount`, `description`) VALUES
(@policy_id, 1, 'Small Loan', 0.00, 999999.99, 'Loans under 1M'),
(@policy_id, 2, 'Medium Loan', 1000000.00, 4999999.99, 'Loans 1M to 5M'),
(@policy_id, 3, 'Large Loan', 5000000.00, 9999999.99, 'Loans 5M to 10M'),
(@policy_id, 4, 'Exceptional Loan', 10000000.00, NULL, 'Loans above 10M');

-- Tier 1: 1 approver (Chairman OR Vice Chairman)
-- Using same slot_number with different required_role to represent alternatives
SET @tier1_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 1);
INSERT INTO `approval_tier_slots` (`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`) VALUES
(@tier1_id, 1, 'alternative', 1, 'chairman', 'Chairman OR Vice Chairman'),
(@tier1_id, 1, 'alternative', 1, 'vice_chairman', 'Chairman OR Vice Chairman');

-- Tier 2: 2 approvers (Chairman + Vice/Secretary)
-- Slot 1: Chairman mandatory
-- Slot 2: Vice Chairman OR Secretary (alternatives with same slot_number, different roles)
SET @tier2_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 2);
INSERT INTO `approval_tier_slots` (`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`) VALUES
(@tier2_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier2_id, 2, 'alternative', 2, 'vice_chairman', 'Vice Chairman OR Secretary'),
(@tier2_id, 2, 'alternative', 2, 'secretary', 'Vice Chairman OR Secretary');

-- Tier 3: 3 approvers (Chairman + Vice + Secretary)
SET @tier3_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 3);
INSERT INTO `approval_tier_slots` (`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`) VALUES
(@tier3_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier3_id, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(@tier3_id, 3, 'mandatory', NULL, 'secretary', 'Secretary');

-- Tier 4: 4 approvers (Chairman + Vice + Secretary + Treasurer)
SET @tier4_id = (SELECT id FROM approval_tiers WHERE policy_id = @policy_id AND tier_number = 4);
INSERT INTO `approval_tier_slots` (`tier_id`, `slot_number`, `slot_type`, `slot_group`, `required_role`, `display_label`) VALUES
(@tier4_id, 1, 'mandatory', NULL, 'chairman', 'Chairman'),
(@tier4_id, 2, 'mandatory', NULL, 'vice_chairman', 'Vice Chairman'),
(@tier4_id, 3, 'mandatory', NULL, 'secretary', 'Secretary'),
(@tier4_id, 4, 'mandatory', NULL, 'treasurer', 'Treasurer');

-- Verification query
SELECT 
    'Schema Created Successfully' AS status,
    (SELECT COUNT(*) FROM approval_policies) AS policies,
    (SELECT COUNT(*) FROM approval_tiers) AS tiers,
    (SELECT COUNT(*) FROM approval_tier_slots) AS slots,
    (SELECT COUNT(*) FROM test_users) AS users;
