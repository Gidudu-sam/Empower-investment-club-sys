-- ============================================================
--  Six-role model -- role rename (identifiers, not just labels)
--  Engine: InnoDB   Charset: utf8mb4
--
--  Follow-on to database/roles_expansion_six_role_model.sql. Renames
--  two of the four roles added there:
--    'it'         -> 'system_admin' (label: System Administrator)
--    'front_desk' -> 'office_admin' (label: Office Administrator)
--
--  UPDATE in place, same role id -- NOT delete+insert. This is what
--  makes the rename safe: any user row's role_id keeps pointing at
--  the exact same role row, so existing assignments transparently
--  become the new name with zero data migration needed, and no
--  duplicate role is ever created. chairman/loans_officer/treasurer/
--  cashier/admin/member/viewer are untouched by this file.
--
--  Deliberately no USE statement -- pass the target database via the
--  CLI invocation instead (mysql -u root <db> < this file), matching
--  the lesson from the internal_voucher_member_subledger.sql incident.
-- ============================================================

UPDATE `roles` SET `name` = 'system_admin', `label` = 'System Administrator' WHERE `name` = 'it';
UPDATE `roles` SET `name` = 'office_admin', `label` = 'Office Administrator' WHERE `name` = 'front_desk';
