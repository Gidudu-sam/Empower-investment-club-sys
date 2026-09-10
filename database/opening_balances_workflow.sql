-- ============================================================
--  Opening Balances Workflow Enhancement
--  Phase 6 Prerequisite Fix 3
--  Date: 2026-08-25
--
--  Extends opening_balances table to support full maker-checker
--  workflow with 5 states and rejection tracking.
--
--  SAFE: Does not modify any existing data.
--  SAFE: Does not touch historical journal entries.
--  SAFE: Does not create any opening balance records.
-- ============================================================

USE `empower_db`;

-- Extend status enum to support full workflow
ALTER TABLE `opening_balances` 
MODIFY COLUMN `status` ENUM('draft','pending_approval','approved','posted','rejected') 
NOT NULL DEFAULT 'draft';

-- Add rejection tracking
ALTER TABLE `opening_balances`
ADD COLUMN `rejection_reason` TEXT NULL AFTER `approved_at`;

-- Verification query (run separately to confirm changes)
-- SHOW COLUMNS FROM opening_balances WHERE Field IN ('status', 'rejection_reason');
