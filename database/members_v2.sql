-- ============================================================
--  Members Table — v2
--  Run this against empower_db AFTER schema.sql
--  Safe to re-run (uses IF NOT EXISTS / IF NOT EXISTS checks)
-- ============================================================

USE `empower_db`;

-- Drop old table if you are doing a clean rebuild
-- DROP TABLE IF EXISTS `members`;

CREATE TABLE IF NOT EXISTS `members` (
    `id`                INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `member_number`     VARCHAR(20)     NOT NULL UNIQUE          COMMENT 'EMP0001 …',
    `first_name`        VARCHAR(80)     NOT NULL,
    `last_name`         VARCHAR(80)     NOT NULL,
    `gender`            ENUM('Male','Female','Other') NOT NULL,
    `date_of_birth`     DATE            NULL,
    `phone`             VARCHAR(20)     NOT NULL UNIQUE,
    `email`             VARCHAR(191)    NULL UNIQUE,
    `national_id`       VARCHAR(50)     NOT NULL UNIQUE,
    `address`           TEXT            NULL,
    `next_of_kin_name`  VARCHAR(150)    NULL,
    `next_of_kin_phone` VARCHAR(20)     NULL,
    `join_date`         DATE            NOT NULL,
    `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `passport_photo`    VARCHAR(255)    NULL                     COMMENT 'Filename only',
    `created_by`        INT UNSIGNED    NULL,
    `created_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_members_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
