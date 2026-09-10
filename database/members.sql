-- ============================================================
--  Members table — run against empower_db
-- ============================================================

USE `empower_db`;

CREATE TABLE IF NOT EXISTS `members` (
    `id`              INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `member_number`   VARCHAR(20)     NOT NULL UNIQUE COMMENT 'e.g. EMP0001',
    `full_name`       VARCHAR(150)    NOT NULL,
    `gender`          ENUM('Male','Female','Other') NOT NULL,
    `date_of_birth`   DATE            NULL,
    `phone`           VARCHAR(20)     NOT NULL,
    `email`           VARCHAR(191)    NULL UNIQUE,
    `address`         TEXT            NULL,
    `national_id`     VARCHAR(50)     NOT NULL UNIQUE,
    `next_of_kin`     VARCHAR(150)    NULL,
    `next_of_kin_phone` VARCHAR(20)   NULL,
    `join_date`       DATE            NOT NULL DEFAULT (CURDATE()),
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `notes`           TEXT            NULL,
    `created_by`      INT UNSIGNED    NULL COMMENT 'FK to users.id',
    `created_at`      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_members_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
