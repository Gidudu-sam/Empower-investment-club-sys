-- Stage 12-B — Personalized Notification Core Engine.
--
-- Purely additive: new nullable/defaulted columns only. No existing
-- column, row, or the existing `fk_notif_user` FK is touched. Idempotent
-- (each ADD COLUMN/INDEX is guarded so the file is safe to run twice).
-- No hardcoded `USE` -- the target database is selected by the invoking
-- `mysql` command, matching every migration since the Stage 11 lesson.

-- Recipient/context columns. recipient_role is audit metadata (the role
-- this row was resolved from at creation time, if any) -- it is never
-- used to re-resolve visibility at read time, so a later role change can
-- never alter who could already see a historical notification.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='recipient_role');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `recipient_role` VARCHAR(50) NULL COMMENT ''Audit metadata: the role this row was resolved from at creation time, if any. Never used to re-resolve visibility at read time.'' AFTER `user_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='member_id');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `member_id` INT UNSIGNED NULL AFTER `recipient_role`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='loan_id');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `loan_id` INT UNSIGNED NULL AFTER `member_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='savings_account_id');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `savings_account_id` INT UNSIGNED NULL AFTER `loan_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='priority');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `priority` ENUM(''low'',''normal'',''high'') NOT NULL DEFAULT ''normal'' AFTER `type`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='action_url');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `action_url` VARCHAR(255) NULL AFTER `savings_account_id`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='event_date');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `event_date` DATE NULL AFTER `action_url`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- NULL-safe uniqueness: the same technique already proven by
-- fixed_monthly_savings.sql / fixed_deposit_accounts.sql / the Stage
-- FD-2 and Stage 11 closure-request tables -- MySQL treats each NULL as
-- distinct in a UNIQUE index, so existing rows (which get NULL here) are
-- never constrained, while every future row gets a real, deterministic,
-- race-condition-safe duplicate guard.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='unique_event_key');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `unique_event_key` VARCHAR(191) NULL AFTER `event_date`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Explicit, intentional system-wide broadcast flag -- replaces the old
-- implicit "user_id IS NULL means everyone" meaning. Defaults to 0 for
-- every existing and future row unless a caller deliberately uses the
-- one dedicated broadcast method.
SET @col_exists = (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND COLUMN_NAME='is_broadcast');
SET @sql = IF(@col_exists=0, 'ALTER TABLE `notifications` ADD COLUMN `is_broadcast` TINYINT(1) NOT NULL DEFAULT 0 AFTER `unique_event_key`', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists = (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND INDEX_NAME='uq_notification_event_key');
SET @sql = IF(@idx_exists=0, 'ALTER TABLE `notifications` ADD UNIQUE KEY `uq_notification_event_key` (`unique_event_key`)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND CONSTRAINT_NAME='fk_notif_member');
SET @sql = IF(@fk_exists=0, 'ALTER TABLE `notifications` ADD CONSTRAINT `fk_notif_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND CONSTRAINT_NAME='fk_notif_loan');
SET @sql = IF(@fk_exists=0, 'ALTER TABLE `notifications` ADD CONSTRAINT `fk_notif_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_exists = (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='notifications' AND CONSTRAINT_NAME='fk_notif_savings_account');
SET @sql = IF(@fk_exists=0, 'ALTER TABLE `notifications` ADD CONSTRAINT `fk_notif_savings_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts`(`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
