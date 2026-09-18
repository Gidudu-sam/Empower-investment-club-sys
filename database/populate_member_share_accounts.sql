-- ============================================================
--  Populate Member Share Accounts
--  
--  This script:
--  1. Creates one share account for every active member
--  2. Links all historical share_transactions to their accounts
--  3. Is IDEMPOTENT - safe to run multiple times
--
--  IMPORTANT: Run this AFTER member_share_accounts_schema.sql
--
--  This does NOT modify:
--  - share transaction amounts
--  - share transaction dates
--  - journal entries
--  - GL balances
--
--  This is purely an account-association change.
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  Step 1: Create share accounts for all active members
--  Uses INSERT IGNORE to be idempotent
-- ------------------------------------------------------------

-- First, we'll use a stored procedure to generate account numbers
-- with proper locking to avoid race conditions

DELIMITER $$

DROP PROCEDURE IF EXISTS `populate_share_accounts`$$

CREATE PROCEDURE `populate_share_accounts`()
BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE v_member_id INT UNSIGNED;
    DECLARE v_member_number VARCHAR(20);
    DECLARE v_join_date DATE;
    DECLARE v_created_by INT UNSIGNED;
    DECLARE v_account_number VARCHAR(20);
    DECLARE v_last_number INT;
    DECLARE v_new_number INT;
    
    -- Cursor to iterate through all active members who don't have share accounts yet
    DECLARE member_cursor CURSOR FOR 
        SELECT m.id, m.member_number, m.join_date, m.created_by
        FROM members m
        LEFT JOIN member_share_accounts msa ON msa.member_id = m.id
        WHERE m.status = 'active'
          AND msa.id IS NULL
        ORDER BY m.id;
    
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    -- Start transaction
    START TRANSACTION;
    
    OPEN member_cursor;
    
    read_loop: LOOP
        FETCH member_cursor INTO v_member_id, v_member_number, v_join_date, v_created_by;
        
        IF done THEN
            LEAVE read_loop;
        END IF;
        
        -- Generate next account number with row lock
        SELECT last_number INTO v_last_number
        FROM journal_number_sequences
        WHERE prefix = 'SHR'
        FOR UPDATE;
        
        SET v_new_number = v_last_number + 1;
        SET v_account_number = CONCAT('SHR-', LPAD(v_new_number, 6, '0'));
        
        -- Update sequence
        UPDATE journal_number_sequences
        SET last_number = v_new_number
        WHERE prefix = 'SHR';
        
        -- Create share account
        INSERT INTO member_share_accounts (
            member_id,
            account_number,
            status,
            opened_date,
            created_by,
            created_at,
            updated_at
        ) VALUES (
            v_member_id,
            v_account_number,
            'active',
            v_join_date,  -- Use member's join date as account opened date
            v_created_by,
            NOW(),
            NOW()
        );
        
    END LOOP;
    
    CLOSE member_cursor;
    
    COMMIT;
    
    -- Show results
    SELECT 
        COUNT(*) AS total_share_accounts_created
    FROM member_share_accounts;
    
END$$

DELIMITER ;

-- Execute the procedure
CALL populate_share_accounts();

-- ------------------------------------------------------------
--  Step 2: Link historical share_transactions to share accounts
--  Based on member_id mapping
-- ------------------------------------------------------------

UPDATE share_transactions st
INNER JOIN member_share_accounts msa ON msa.member_id = st.member_id
SET st.share_account_id = msa.id
WHERE st.share_account_id IS NULL;

-- ------------------------------------------------------------
--  Step 3: Verification
-- ------------------------------------------------------------

SELECT '=== MEMBER SHARE ACCOUNTS POPULATION VERIFICATION ===' AS status;

SELECT 
    'Total Members (Active)' AS metric,
    COUNT(*) AS value
FROM members
WHERE status = 'active'

UNION ALL

SELECT 
    'Share Accounts Created' AS metric,
    COUNT(*) AS value
FROM member_share_accounts

UNION ALL

SELECT 
    'Members Without Share Account' AS metric,
    COUNT(*) AS value
FROM members m
LEFT JOIN member_share_accounts msa ON msa.member_id = m.id
WHERE m.status = 'active' AND msa.id IS NULL

UNION ALL

SELECT 
    'Historical Transactions' AS metric,
    COUNT(*) AS value
FROM share_transactions

UNION ALL

SELECT 
    'Transactions Linked to Accounts' AS metric,
    COUNT(*) AS value
FROM share_transactions
WHERE share_account_id IS NOT NULL

UNION ALL

SELECT 
    'Transactions NOT Linked' AS metric,
    COUNT(*) AS value
FROM share_transactions
WHERE share_account_id IS NULL;

-- Show sample share accounts
SELECT 
    '=== SAMPLE SHARE ACCOUNTS ===' AS info;

SELECT 
    msa.account_number,
    m.member_number,
    CONCAT(m.first_name, ' ', m.last_name) AS member_name,
    msa.status,
    msa.opened_date,
    COUNT(st.id) AS transaction_count,
    COALESCE(SUM(st.amount), 0) AS total_shares_value
FROM member_share_accounts msa
INNER JOIN members m ON m.id = msa.member_id
LEFT JOIN share_transactions st ON st.share_account_id = msa.id
GROUP BY msa.id, msa.account_number, m.member_number, m.first_name, m.last_name, msa.status, msa.opened_date
ORDER BY msa.account_number
LIMIT 10;

-- Verify GL 3010 has not changed
SELECT 
    '=== GL 3010 VERIFICATION (should be 56,455,620) ===' AS info;

SELECT 
    a.code,
    a.name,
    COALESCE(SUM(jl.credit), 0) - COALESCE(SUM(jl.debit), 0) AS balance
FROM accounts a
LEFT JOIN journal_lines jl ON jl.account_id = a.id
WHERE a.code = '3010'
GROUP BY a.id, a.code, a.name;

-- Verify historical share total unchanged
SELECT 
    '=== HISTORICAL SHARE TOTAL VERIFICATION (should be 51,485,620) ===' AS info;

SELECT 
    COUNT(*) AS transaction_count,
    COUNT(DISTINCT st.member_id) AS unique_members,
    SUM(st.amount) AS total_amount
FROM share_transactions st;

-- Clean up
DROP PROCEDURE IF EXISTS `populate_share_accounts`;

SELECT '=== POPULATION COMPLETE ===' AS status;
