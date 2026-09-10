-- SA-6 (Database Recovery & Restore, 2026-09).
-- Purely additive: one new table. Nothing existing is altered.
--
-- Records every backup verification/restore-test run performed through
-- the SA-6 recovery interface. This is an audit trail of VERIFICATION
-- ACTIVITY ONLY -- it never represents that production was restored,
-- since no code path in this stage ever writes to the production
-- database as part of a restore.

CREATE TABLE IF NOT EXISTS `database_recoveries` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `backup_id` VARCHAR(64) NOT NULL COMMENT 'BackupInventoryService id (sha256-derived), never a raw filesystem path',
    `backup_filename` VARCHAR(255) NOT NULL,
    `backup_checksum` VARCHAR(64) NULL COMMENT 'sha256 of the backup file at verification time',
    `target_database` VARCHAR(64) NOT NULL COMMENT 'always the fixed isolated SA-6 test database name -- never empower_db',
    `status` ENUM('discovered','structurally_valid','restore_tested','verified','failed','rejected') NOT NULL DEFAULT 'discovered',
    `restore_exit_code` INT NULL,
    `restore_output` TEXT NULL,
    `verification_summary_json` TEXT NULL COMMENT 'schema/data/accounting/integrity findings + recovery point, as JSON',
    `error_message` TEXT NULL,
    `actor_id` INT UNSIGNED NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_backup` (`backup_id`),
    CONSTRAINT `fk_recovery_actor` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
