-- ============================================================
--  Members Table — v3 (Additional fields)
--  Adds: station, present_address, home_address,
--         next_of_kin_address, next_of_kin_relation
--  Safe to re-run (uses IF NOT EXISTS column checks)
-- ============================================================

USE `empower_db`;

-- Add station (Workplace/School/Organization)
ALTER TABLE `members` ADD COLUMN IF NOT EXISTS
    `station` VARCHAR(200) NULL COMMENT 'Workplace/School/Organization'
    AFTER `national_id`;

-- Add present_address (current residential address)
ALTER TABLE `members` ADD COLUMN IF NOT EXISTS
    `present_address` TEXT NULL COMMENT 'Present/Current Address'
    AFTER `station`;

-- Add home_address (permanent home address)
ALTER TABLE `members` ADD COLUMN IF NOT EXISTS
    `home_address` TEXT NULL COMMENT 'Home/Permanent Address'
    AFTER `present_address`;

-- Add next_of_kin_address
ALTER TABLE `members` ADD COLUMN IF NOT EXISTS
    `next_of_kin_address` TEXT NULL COMMENT 'Next of Kin Address'
    AFTER `next_of_kin_phone`;

-- Add next_of_kin_relation
ALTER TABLE `members` ADD COLUMN IF NOT EXISTS
    `next_of_kin_relation` VARCHAR(100) NULL COMMENT 'Relationship to member'
    AFTER `next_of_kin_address`;
