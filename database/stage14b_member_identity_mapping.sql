-- Stage 14-B — Secure Member Identity Mapping & Account Provisioning
-- Additive only. No existing table is dropped or recreated. No existing
-- row is modified by this migration (the one candidate mapping identified
-- during the audit -- user id 299 to member id 21 -- is applied, if at
-- all, as a separate, explicitly-confirmed UPDATE after this migration,
-- never as part of the schema change itself).
--
-- Phase 1 (identity link): users.member_id, nullable, FK to members.id,
-- with ON DELETE SET NULL / ON UPDATE CASCADE per the brief. A standard
-- UNIQUE KEY on a nullable column is used rather than a Postgres-style
-- partial/filtered unique index -- confirmed against this server
-- (MariaDB 10.4.32) that a UNIQUE KEY permits unlimited NULL values and
-- rejects only duplicate non-NULL values, which is exactly "one member
-- may have at most one linked account, staff accounts stay NULL".
--
-- Also included (needed by Phase 3/Phase 6 of this same stage, not a
-- later phase): users.force_password_change, for newly-provisioned
-- member accounts. Phase 7 (lockout, password reset) columns are
-- deliberately NOT part of this migration -- that phase is explicitly
-- deferred to a separate, later stage.

ALTER TABLE `users`
    ADD COLUMN `member_id` INT UNSIGNED NULL AFTER `role_id`,
    ADD COLUMN `force_password_change` TINYINT(1) NOT NULL DEFAULT 0 AFTER `password_hash`;

ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_member_id` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD UNIQUE KEY `uq_users_member_id` (`member_id`);
