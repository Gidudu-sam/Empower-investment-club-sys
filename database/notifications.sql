-- ============================================================
--  Notifications Module — Database Table
--  Engine: MySQL 8+   Charset: utf8mb4
-- ============================================================

USE `empower_db`;

CREATE TABLE IF NOT EXISTS `notifications` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`          VARCHAR(255) NOT NULL,
    `message`        TEXT         NOT NULL,
    `type`           ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
    `reference_type` VARCHAR(50)  NULL COMMENT 'loan, savings, withdrawal, system, user',
    `reference_id`   INT UNSIGNED NULL,
    `user_id`        INT UNSIGNED NULL COMMENT 'Target user (NULL = all admins)',
    `is_read`        TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `read_at`        TIMESTAMP    NULL,
    INDEX `idx_user_read` (`user_id`, `is_read`),
    INDEX `idx_type` (`type`),
    INDEX `idx_ref` (`reference_type`, `reference_id`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
