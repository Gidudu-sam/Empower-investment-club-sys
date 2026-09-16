-- Stage 3A: Create Disposable Test Database
-- This creates empower_stage3_test for testing only
-- NEVER run against production

DROP DATABASE IF EXISTS `empower_stage3_test`;
CREATE DATABASE `empower_stage3_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `empower_stage3_test`;

-- Users table (minimal)
CREATE TABLE `users` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id` INT UNSIGNED NOT NULL,
    `member_id` INT UNSIGNED NULL,
    `full_name` VARCHAR(150) NOT NULL,
    `email` VARCHAR(191) NOT NULL UNIQUE,
    `password_hash` VARCHAR(255) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_role` (`role_id`),
    KEY `idx_member` (`member_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Roles table
CREATE TABLE `roles` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(50) NOT NULL UNIQUE,
    `label` VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed officer roles
INSERT INTO `roles` (`id`, `name`, `label`) VALUES
(1, 'admin', 'Administrator'),
(2, 'treasurer', 'Treasurer'),
(3, 'member', 'Member'),
(7, 'chairman', 'Chairman / Team Leader'),
(11, 'secretary', 'Secretary'),
(12, 'vice_chairman', 'Vice Chairman');

-- Create test users (one for each officer role + regular member)
INSERT INTO `users` (`id`, `role_id`, `member_id`, `full_name`, `email`, `password_hash`) VALUES
(1, 7, 1, 'Test Chairman', 'chairman@test.local', '$2y$12$dummyhash1'),
(2, 12, 2, 'Test Vice Chairman', 'vice@test.local', '$2y$12$dummyhash2'),
(3, 11, 3, 'Test Secretary', 'secretary@test.local', '$2y$12$dummyhash3'),
(4, 2, 4, 'Test Treasurer', 'treasurer@test.local', '$2y$12$dummyhash4'),
(5, 3, 5, 'Test Member', 'member@test.local', '$2y$12$dummyhash5'),
(6, 1, NULL, 'Test Admin', 'admin@test.local', '$2y$12$dummyhash6');

-- Members table (minimal)
CREATE TABLE `members` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_number` VARCHAR(20) NOT NULL UNIQUE,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed test members
INSERT INTO `members` (`id`, `member_number`, `first_name`, `last_name`) VALUES
(1, 'MEM001', 'Chairman', 'Officer'),
(2, 'MEM002', 'Vice', 'Officer'),
(3, 'MEM003', 'Secretary', 'Officer'),
(4, 'MEM004', 'Treasurer', 'Officer'),
(5, 'MEM005', 'Regular', 'Member'),
(6, 'MEM006', 'Another', 'Member');

-- Loans table (minimal - for testing)
CREATE TABLE `loans` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_number` VARCHAR(20) NOT NULL UNIQUE,
    `member_id` INT UNSIGNED NOT NULL,
    `loan_amount` DECIMAL(15,2) NOT NULL,
    `amount` DECIMAL(15,2) NOT NULL,
    `status` ENUM('draft','pending_approval','approved','rejected','active','completed','defaulted') NOT NULL DEFAULT 'draft',
    `recorded_by` INT UNSIGNED NOT NULL,
    `submitted_at` TIMESTAMP NULL,
    `approved_by` INT UNSIGNED NULL,
    `approved_at` TIMESTAMP NULL,
    `rejected_by` INT UNSIGNED NULL,
    `rejected_at` TIMESTAMP NULL,
    `rejection_reason` TEXT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY `idx_member` (`member_id`),
    KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'Base schema created' AS status;
SELECT DATABASE() AS current_database;
