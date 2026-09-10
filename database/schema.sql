-- ============================================================
--  Empower Investment Club — Database Schema
--  Engine: MySQL 8+   Charset: utf8mb4
-- ============================================================

CREATE DATABASE IF NOT EXISTS `empower_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `empower_db`;

-- ------------------------------------------------------------
--  Roles
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `roles` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(50)  NOT NULL UNIQUE,
    `label`      VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `roles` (`name`, `label`) VALUES
    ('admin',     'Administrator'),
    ('treasurer', 'Treasurer'),
    ('member',    'Member');

-- ------------------------------------------------------------
--  Users
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id`         INT UNSIGNED NOT NULL DEFAULT 3,
    `full_name`       VARCHAR(150) NOT NULL,
    `email`           VARCHAR(191) NOT NULL UNIQUE,
    `phone`           VARCHAR(20)  NULL,
    `password_hash`   VARCHAR(255) NOT NULL,
    `avatar`          VARCHAR(255) NULL,
    `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at`   TIMESTAMP    NULL,
    `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Default admin account  (password: Admin@1234 — change on first login)
INSERT IGNORE INTO `users` (`role_id`, `full_name`, `email`, `phone`, `password_hash`)
VALUES (
    1,
    'System Administrator',
    'admin@empower.local',
    NULL,
    '$2y$12$YourHashHere_ReplaceWithBcryptOfAdmin1234'  -- placeholder; see seed.php
);

-- ------------------------------------------------------------
--  Activity / Audit Log
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `action`      VARCHAR(100) NOT NULL,
    `description` TEXT         NULL,
    `ip_address`  VARCHAR(45)  NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
