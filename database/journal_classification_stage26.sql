-- Stage 26: additive journal-entry classification column.
-- Distinguishes authoritative (live) postings from forensically-classified
-- dummy/test activity and unresolved-unknown activity (Stage 24/25 findings).
-- Purely additive: no existing row, column, or constraint is modified.
-- Default 'live' is deliberate (existing accounting semantics assume every
-- posting is authoritative until proven otherwise) -- the 42 known-dummy
-- rows are explicitly reclassified by id immediately after this migration,
-- in the same deployment, never left at the default.

ALTER TABLE `journal_entries`
  ADD COLUMN `data_classification` ENUM('live','dummy','unknown') NOT NULL DEFAULT 'live'
    AFTER `reversed`;
