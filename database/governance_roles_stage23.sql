-- Stage 23 (Board Governance Roles, Secretary & Vice Chairman, 2026-09).
-- Purely additive: two new rows in the existing `roles` table. No table
-- is created, altered, or dropped. No existing row is modified.
--
-- These two roles slot into the existing users.role_id -> roles.id FK
-- exactly like every other role (admin, treasurer, chairman, etc.) --
-- no schema change is required anywhere else for login, session
-- authorization, or user-management UI to support them, since all three
-- already derive their role list dynamically from this table.

INSERT INTO `roles` (`name`, `label`) VALUES
    ('secretary', 'Secretary'),
    ('vice_chairman', 'Vice Chairman');
