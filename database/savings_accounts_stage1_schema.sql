-- ============================================================
--  Savings Accounts Module — STAGE 1: schema only
--  Engine: InnoDB   Charset: utf8mb4
--
--  Deliberately named `member_savings_accounts` (not `savings_accounts`)
--  to avoid any collision with the orphaned, forensically-untouched
--  legacy table of that name. That table is NOT read, modified, or
--  referenced anywhere in this migration.
--
--  No historical data is touched in this file. `savings` gains one
--  additive, nullable column only. No existing row is altered.
-- ============================================================

USE `empower_db`;

CREATE TABLE `organizations` (
    `id`                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`                  VARCHAR(200) NOT NULL,
    `registration_number`   VARCHAR(100) NULL,
    `contact_phone`         VARCHAR(20) NULL,
    `contact_email`         VARCHAR(191) NULL,
    `address`               TEXT NULL,
    `created_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `member_savings_accounts` (
    `id`                      INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_number`          VARCHAR(20) NOT NULL,
    `account_type`            ENUM('compulsory','voluntary','joint','corporate') NOT NULL,
    `ownership_type`          ENUM('individual','joint','corporate') NOT NULL,
    `status`                  ENUM('active','dormant','closed') NOT NULL DEFAULT 'active',
    `opened_date`             DATE NOT NULL,
    `closed_date`             DATE NULL,
    `qualification_met_date`  DATE NULL COMMENT 'Compulsory only: date the 2-deposit/40,000 threshold was first reached. Permanent once set -- never cleared.',
    `created_by`              INT UNSIGNED NULL,
    `created_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_account_number` (`account_number`),
    KEY `idx_account_type` (`account_type`),
    KEY `idx_account_status` (`status`),
    CONSTRAINT `fk_msa_created_by` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Ownership: exactly one of member_id / organization_id is set
--  per row. DB-level guards cover "exactly one populated" and
--  "no duplicate holder of either kind per account" -- role vs.
--  account_type consistency (e.g. a corporate account should only
--  ever gain an 'organization' holder) is application-level logic
--  in the model layer, since a CHECK constraint cannot reference
--  another table's account_type. Documented here rather than
--  overclaiming DB-level enforcement it cannot actually provide.
-- ------------------------------------------------------------
CREATE TABLE `savings_account_holders` (
    `id`               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `account_id`       INT UNSIGNED NOT NULL,
    `member_id`        INT UNSIGNED NULL,
    `organization_id`  INT UNSIGNED NULL,
    `role`             ENUM('primary','joint','organization') NOT NULL,
    `added_at`         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_account_member` (`account_id`, `member_id`),
    UNIQUE KEY `uk_account_organization` (`account_id`, `organization_id`),
    KEY `idx_holder_account` (`account_id`),
    KEY `idx_holder_member` (`member_id`),
    CONSTRAINT `fk_holder_account` FOREIGN KEY (`account_id`) REFERENCES `member_savings_accounts`(`id`),
    CONSTRAINT `fk_holder_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`),
    CONSTRAINT `fk_holder_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
    CONSTRAINT `chk_holder_exactly_one` CHECK (
        (`member_id` IS NOT NULL AND `organization_id` IS NULL) OR
        (`member_id` IS NULL AND `organization_id` IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `organization_representatives` (
    `id`                       INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `organization_id`         INT UNSIGNED NOT NULL,
    `member_id`                INT UNSIGNED NULL COMMENT 'A representative need not be an Empower member',
    `full_name`                VARCHAR(150) NOT NULL,
    `phone`                    VARCHAR(20) NULL,
    `role`                     VARCHAR(100) NULL,
    `is_authorized_signatory`  TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY `idx_rep_organization` (`organization_id`),
    CONSTRAINT `fk_rep_organization` FOREIGN KEY (`organization_id`) REFERENCES `organizations`(`id`),
    CONSTRAINT `fk_rep_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
--  Additive only. Nullable. member_id is NOT removed or touched.
--  No existing `savings` row's data changes -- this column has
--  no default write path yet; it will be populated only by the
--  Stage-3+ account-aware posting code and, later, the explicitly
--  separate historical-migration script (not run in this file).
-- ------------------------------------------------------------
ALTER TABLE `savings` ADD COLUMN `savings_account_id` INT UNSIGNED NULL AFTER `member_id`;
ALTER TABLE `savings` ADD CONSTRAINT `fk_savings_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts`(`id`);

-- ------------------------------------------------------------
--  Account numbering — reuse the existing row-locked sequence
--  mechanism (already serving JE/EXP/OB/INV/INVTX/IV).
-- ------------------------------------------------------------
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('CS', 0);
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('VS', 0);
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('JS', 0);
INSERT IGNORE INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('CORP', 0);
