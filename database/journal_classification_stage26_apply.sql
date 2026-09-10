-- Stage 26: classify the 45 currently-surviving journal entries per the verified
-- Stage 25 register (results/stage25_.../03_journal_classification_register.json).
-- Every id below was independently re-verified against production this stage
-- before being written here -- none is copied blindly from a prior stage.

-- 3 LIVE (real expense postings) -- default already "live", explicit for clarity/auditability
UPDATE `journal_entries` SET `data_classification` = 'live' WHERE `id` IN (145,1485,4076);

-- 42 DUMMY_TEST (34 loan-related + 8 savings-related orphan journals, Stage 19D/22/24)
UPDATE `journal_entries` SET `data_classification` = 'dummy' WHERE `id` IN (17,18,47,48,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,115,116,117,118,119,120,129,130,131,138,139,140,141);

-- 0 UNKNOWN currently -- no journal entry in production requires this classification
-- (the member_id=3 savings anomaly is an OPERATIONAL row, never posted to the ledger,
-- so it has no journal_entries row to classify at all)
