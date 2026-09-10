-- ============================================================
-- Stage 6-C — Persistent Manual Member Status Override
-- Additive migration: adds members.status_source
--
-- Distinguishes a manually-chosen member status (set explicitly by an
-- admin via MemberController::changeStatus() or an actual status change
-- on the Edit-Member form) from a system-computed one
-- (MemberModel::syncDormantStatus()). Automatic dormancy sync is
-- modified (application code, not this migration) to only ever touch
-- rows where status_source = 'automatic'.
--
-- Safe, additive, non-destructive:
--   - adds exactly one NOT NULL column with a DEFAULT, so the ALTER
--     itself backfills every existing row to 'automatic' -- no separate
--     UPDATE statement is required or performed.
--   - does not touch any other column, table, or row.
--   - preserves every existing members.status value exactly as-is.
-- ============================================================

ALTER TABLE `members`
    ADD COLUMN `status_source` ENUM('automatic','manual') NOT NULL DEFAULT 'automatic'
    AFTER `status`;
