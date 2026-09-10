-- ============================================================
--  Loan Provisioning — Stage 21-D Hardening
--  Engine: InnoDB   Charset: utf8mb4
--
--  Resolves Stage 21-C Finding 1 (active-policy uniqueness): the
--  database previously had no constraint preventing two
--  loan_provisioning_policies rows from both being status='active'
--  simultaneously. This adds a generated-column + unique-index
--  safeguard (MariaDB 10.4 supports this) -- a NULL value never
--  collides with another NULL in a unique index, but two non-NULL
--  values (i.e. two 'active' rows) would, so at most one row can ever
--  be 'active' at a time.
--
--  No existing row is touched. PROV-001 remains the sole active
--  policy (verified before and after this migration).
-- ============================================================

USE `empower_db`;

ALTER TABLE `loan_provisioning_policies`
    ADD COLUMN `active_singleton` TINYINT(1)
        GENERATED ALWAYS AS (IF(`status` = 'active', 1, NULL)) VIRTUAL
        COMMENT 'Stage 21-C Finding 1 hardening, NULL unless status=active, unique index below enforces at most one active policy at a time',
    ADD UNIQUE KEY `uk_policy_active_singleton` (`active_singleton`);
