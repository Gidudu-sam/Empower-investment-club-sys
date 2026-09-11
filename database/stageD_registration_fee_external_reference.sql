-- ============================================================
-- Stage D — Registration Fee Payment Traceability (approved Stage C, G1)
-- Additive migration: adds member_fees.external_reference
--
-- Purpose: lets staff optionally record the external payment-provider
-- reference (a mobile-money transaction code, a bank deposit slip
-- number, a cheque number, etc.) when marking a fee as paid --
-- genuinely separate from member_fees.reference_number (the permanent,
-- system-generated FEE-###### internal charge/obligation reference,
-- unchanged, ungenerated-differently, untouched by this migration) and
-- from member_fees.cash_reference_number (the internal CHR-/CHA- cash
-- reference, also untouched).
--
-- Safe, additive, non-destructive:
--   - adds exactly one nullable column with no default and no
--     constraint -- every existing row's new value is NULL, requiring
--     no backfill, no data migration, and no change to any existing
--     row's meaning.
--   - does not touch reference_number, cash_reference_number, status,
--     journal_entry_id, or any other existing column.
--   - does not touch any other table (fees, journal_entries,
--     journal_lines, accounts, savings, loans, members, users, roles,
--     permissions all remain untouched).
-- ============================================================

ALTER TABLE `member_fees`
    ADD COLUMN `external_reference` VARCHAR(100) NULL
    AFTER `cash_reference_number`;
