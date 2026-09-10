-- SA-5 (Controlled Corrections, Reversals & Financial Remediation, 2026-09).
-- Purely additive: one new table. Nothing existing is altered.
--
-- Tracks the PREPARED -> CONFIRMED -> EXECUTED/FAILED/CANCELLED lifecycle of
-- every controlled correction request. This is the durable record that
-- makes a correction "permanently traceable" (SA-5 brief Section 9/26) and
-- provides the idempotency check (Section 25): before executing, the
-- service checks this table's own status first, before ever calling
-- JournalService::reverse() (which has its own independent idempotency
-- check too, via reversal_of_id).

CREATE TABLE IF NOT EXISTS `corrections` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `correction_number` VARCHAR(20) NOT NULL,
    `correction_type` VARCHAR(50) NOT NULL COMMENT 'e.g. REVERSE_JOURNAL -- see ControlledCorrectionService::CORRECTION_TYPES for the full registry',
    `target_entity_type` VARCHAR(50) NOT NULL COMMENT 'e.g. journal_entry',
    `target_entity_id` INT UNSIGNED NOT NULL,
    `target_reference` VARCHAR(50) NULL COMMENT 'e.g. JE00125, for display without a join',
    `target_classification` VARCHAR(20) NULL COMMENT 'live/dummy/unknown at prepare time -- the SA-5 safety gate decision is based on this',
    `target_fingerprint` VARCHAR(64) NULL COMMENT 'sha256 of target state at prepare time, re-checked at execute time (stale-data protection)',
    `original_state_json` TEXT NULL,
    `detected_issue` TEXT NULL,
    `reason` TEXT NOT NULL,
    `status` ENUM('prepared','confirmed','executed','failed','cancelled') NOT NULL DEFAULT 'prepared',
    `resulting_journal_entry_id` INT UNSIGNED NULL,
    `resulting_journal_entry_number` VARCHAR(20) NULL,
    `failure_reason` TEXT NULL,
    `prepared_by` INT UNSIGNED NOT NULL,
    `prepared_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `confirmed_by` INT UNSIGNED NULL,
    `confirmed_at` TIMESTAMP NULL,
    `executed_by` INT UNSIGNED NULL,
    `executed_at` TIMESTAMP NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_correction_number` (`correction_number`),
    KEY `idx_target` (`target_entity_type`, `target_entity_id`),
    KEY `idx_status` (`status`),
    CONSTRAINT `fk_correction_prepared_by` FOREIGN KEY (`prepared_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_correction_confirmed_by` FOREIGN KEY (`confirmed_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_correction_executed_by` FOREIGN KEY (`executed_by`) REFERENCES `users` (`id`),
    CONSTRAINT `fk_correction_resulting_journal` FOREIGN KEY (`resulting_journal_entry_id`) REFERENCES `journal_entries` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
