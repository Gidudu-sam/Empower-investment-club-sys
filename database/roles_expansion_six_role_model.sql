-- ============================================================
--  Six-role operational model — additive role rows only.
--  Engine: InnoDB   Charset: utf8mb4
--
--  Purely additive: 4 new rows in `roles`. No existing role, user,
--  or permission row is altered. No schema change (no ALTER TABLE) --
--  `roles` already has the id/name/label shape these rows need.
--
--  Deliberately no USE statement -- pass the target database via the
--  CLI invocation instead (mysql -u root <db> < this file), matching
--  the lesson from the internal_voucher_member_subledger.sql incident
--  where a stray USE line silently redirected a migration to
--  production ahead of testing.
--
--  NOTE: 'front_desk' and 'it' were later renamed in place (same role
--  id, name/label updated) to 'office_admin'/'Office Administrator'
--  and 'system_admin'/'System Administrator' -- see the follow-up
--  database/roles_rename_office_system_admin.sql, which must be run
--  after this file on any fresh environment. This file is left as an
--  accurate historical record of what was originally inserted; it is
--  not rewritten.
-- ============================================================

INSERT INTO `roles` (`name`, `label`) VALUES
    ('chairman',      'Chairman / Team Leader'),
    ('loans_officer', 'Loans Officer'),
    ('front_desk',    'Front Desk Manager'),
    ('it',            'IT Personnel');
