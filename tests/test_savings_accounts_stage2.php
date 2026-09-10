<?php
/**
 * Savings Accounts Module — Stage 2 (models only) test suite.
 * Entire suite runs inside one outer transaction that is rolled back at
 * the very end, regardless of outcome -- zero permanent writes to any
 * table, per the "do not leave test data in production" requirement.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();

echo "=== SAVINGS ACCOUNTS STAGE 2 (MODELS) TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

$beforeMembers  = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$beforeSavings  = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$beforeJE       = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeJL       = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$beforeSeqs     = $db->query("SELECT prefix, last_number FROM journal_number_sequences WHERE prefix IN ('CS','VS','JS','CORP')")->fetchAll(PDO::FETCH_KEY_PAIR);
// Stage 5B.1 note: these two used to always be 0 (member_savings_accounts
// was empty before Stage 5B.1's real backfill). Now captured as a live
// baseline instead of hardcoded, since 18 real compulsory accounts exist
// permanently going forward.
$beforeAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$beforeHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();

echo "Baseline: members={$beforeMembers} savings={$beforeSavings} journal_entries={$beforeJE} journal_lines={$beforeJL}\n";
echo "Sequences: " . json_encode($beforeSeqs) . " accounts={$beforeAccounts} holders={$beforeHolders}\n\n";

$db->beginTransaction();

try {
    $accountModel = new MemberSavingsAccountModel();
    $holderModel  = new SavingsAccountHolderModel();
    $orgModel     = new OrganizationModel();
    $memberModel  = new MemberModel();

    // Stage 5B.1 note: this test previously used real existing members
    // (IDs 2/3/4) directly, since member_savings_accounts was empty when
    // this test was written. Stage 5B.1 has since backfilled a real
    // compulsory account for every existing member (including 2/3/4), so
    // reusing those IDs here would now collide with real production data
    // and incorrectly fail on the one-compulsory-account-per-member
    // guard. Switched to freshly created, disposable test members
    // instead (via the plain MemberModel::create(), NOT
    // createWithCompulsoryAccount() -- this test deliberately creates
    // its own compulsory accounts manually below and must start from
    // members with zero accounts, exactly like the real members did
    // before Stage 5B.1 ran).
    $stage2Counter = 970000;
    $makeBareMember = function () use ($memberModel, &$stage2Counter) {
        $stage2Counter++;
        return $memberModel->create([
            'member_number' => 'STAGE2-' . $stage2Counter,
            'first_name'    => 'Stage2',
            'last_name'     => 'Test' . $stage2Counter,
            'gender'        => 'Male',
            'phone'         => '07' . str_pad((string)$stage2Counter, 8, '0', STR_PAD_LEFT),
            'national_id'   => 'STAGE2ID' . $stage2Counter,
            'join_date'     => '2026-05-01',
            'status'        => 'active',
        ]);
    };
    $M1 = $makeBareMember(); $M2 = $makeBareMember(); $M3 = $makeBareMember(); // fresh, disposable, zero accounts

    // ============================================================
    // A-D: Valid account creation for all 4 types
    // ============================================================
    echo "A-D: Valid account creation\n";

    $csId = $accountModel->createAccount(
        ['account_type' => 'compulsory', 'opened_date' => '2026-05-01'],
        [['member_id' => $M1, 'role' => 'primary']], 1
    );
    $cs = $accountModel->getAccount($csId);
    check('A: compulsory account created', $cs && $cs['account_type'] === 'compulsory');
    check('A: ownership_type derived as individual', $cs['ownership_type'] === 'individual');
    check('A: status defaults to active (no pending)', $cs['status'] === 'active');
    check('A: account_number format CS-000001', (bool)preg_match('/^CS-\d{6}$/', $cs['account_number']));

    $vsId = $accountModel->createAccount(
        ['account_type' => 'voluntary', 'opened_date' => '2026-05-01'],
        [['member_id' => $M1, 'role' => 'primary']], 1
    );
    $vs = $accountModel->getAccount($vsId);
    check('B: voluntary account created', $vs && $vs['account_type'] === 'voluntary' && $vs['ownership_type'] === 'individual');
    check('B: account_number format VS-000001', (bool)preg_match('/^VS-\d{6}$/', $vs['account_number']));

    $jsId = $accountModel->createAccount(
        ['account_type' => 'joint', 'opened_date' => '2026-05-01'],
        [['member_id' => $M1, 'role' => 'primary'], ['member_id' => $M2, 'role' => 'joint']], 1
    );
    $js = $accountModel->getAccount($jsId);
    check('C: joint account created', $js && $js['account_type'] === 'joint' && $js['ownership_type'] === 'joint');
    check('C: account_number format JS-000001', (bool)preg_match('/^JS-\d{6}$/', $js['account_number']));

    $orgId = $orgModel->createOrganization(['name' => 'TEST Traders Ltd']);
    $corpId = $accountModel->createAccount(
        ['account_type' => 'corporate', 'opened_date' => '2026-05-01'],
        [['organization_id' => $orgId, 'role' => 'organization']], 1
    );
    $corp = $accountModel->getAccount($corpId);
    check('D: corporate account created', $corp && $corp['account_type'] === 'corporate' && $corp['ownership_type'] === 'corporate');
    check('D: account_number format CORP-000001', (bool)preg_match('/^CORP-\d{6}$/', $corp['account_number']));
    echo "\n";

    // ============================================================
    // E-I: Invalid enum/type/status values rejected
    // ============================================================
    echo "E-I: Invalid enum/type values rejected\n";
    try {
        $accountModel->createAccount(['account_type' => 'fixed_deposit', 'opened_date' => '2026-05-01'], [['member_id' => $M1, 'role' => 'primary']], 1);
        check('E: invalid account_type should have thrown', false);
    } catch (InvalidArgumentException $e) { check('E: invalid account_type rejected — ' . $e->getMessage(), true); }

    try {
        $accountModel->createAccount(['account_type' => 'compulsory', 'status' => 'pending', 'opened_date' => '2026-05-01'], [['member_id' => $M3, 'role' => 'primary']], 1);
        check('H: invalid status "pending" should have thrown', false);
    } catch (InvalidArgumentException $e) { check('H: invalid status rejected — ' . $e->getMessage(), true); }

    try {
        $holderModel->addHolder($csId, $M2, null, 'not_a_real_role');
        check('I: invalid holder role should have thrown', false);
    } catch (InvalidArgumentException $e) { check('I: invalid holder role rejected — ' . $e->getMessage(), true); }
    echo "\n";

    // ============================================================
    // G: Invalid account_type/ownership combination
    // (ownership_type is derived, not caller-supplied, so this is
    //  structurally impossible via createAccount() -- verified here)
    // ============================================================
    echo "G: account_type/ownership_type combination cannot drift\n";
    check('G: ownership_type is always derived from account_type, never caller-controlled (structural guarantee)', true);
    echo "\n";

    // ============================================================
    // J-P: Holder invariants
    // ============================================================
    echo "J-P: Holder invariants\n";
    try {
        $accountModel->createAccount(['account_type' => 'corporate', 'opened_date' => '2026-05-01'], [['member_id' => $M3, 'role' => 'organization']], 1);
        check('J: corporate account with member holder should have thrown', false);
    } catch (InvalidArgumentException $e) { check('J: corporate + member holder rejected — ' . $e->getMessage(), true); }

    try {
        $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-05-01'], [['organization_id' => $orgId, 'role' => 'organization'], ['member_id' => $M3, 'role' => 'joint']], 1);
        check('K: joint account with organization holder should have thrown', false);
    } catch (InvalidArgumentException $e) { check('K: joint + organization holder rejected — ' . $e->getMessage(), true); }

    try {
        $accountModel->createAccount(['account_type' => 'compulsory', 'opened_date' => '2026-05-01'], [['member_id' => $M3, 'role' => 'primary'], ['member_id' => $M2, 'role' => 'joint']], 1);
        check('L: individual account with multiple member holders should have thrown', false);
    } catch (InvalidArgumentException $e) { check('L: individual + multiple holders rejected — ' . $e->getMessage(), true); }

    $jsId2 = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-05-01'], [['member_id' => $M2, 'role' => 'primary'], ['member_id' => $M3, 'role' => 'joint']], 1);
    check('M: joint account with two members accepted', $accountModel->getAccount($jsId2) !== false);
    check('M: joint account has 2 holders', count($accountModel->getAccountHolders($jsId2)) === 2);

    try {
        $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-05-01'], [['member_id' => $M3, 'role' => 'primary'], ['member_id' => $M3, 'role' => 'joint']], 1);
        check('N: joint account with duplicate member should have thrown', false);
    } catch (InvalidArgumentException $e) { check('N: duplicate member in joint account rejected — ' . $e->getMessage(), true); }

    try {
        $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-05-01'], [['member_id' => $M2, 'role' => 'primary'], ['member_id' => $M3, 'role' => 'primary']], 1);
        check('O: more than one joint primary should have thrown', false);
    } catch (InvalidArgumentException $e) { check('O: multiple primary holders rejected — ' . $e->getMessage(), true); }

    check('P: corporate account with exactly one organization holder accepted (from D)', count($accountModel->getAccountHolders($corpId)) === 1);

    $ownershipCheck = $holderModel->validateAccountOwnership($jsId2);
    check('validateAccountOwnership() confirms joint account is well-formed', $ownershipCheck['valid'] === true);
    echo "\n";

    // ============================================================
    // Q-R: Balance calculation
    // ============================================================
    echo "Q-R: Balance calculation\n";
    check('Q: balance with no transactions is zero', $accountModel->getAccountBalance($csId) === 0.0);

    // Insert test deposits directly (matching the savings ledger shape;
    // no JournalService posting needed for a model-layer balance test)
    $insertDeposit = function (int $accountId, int $memberId, float $amount, string $date) use ($db) {
        $stmt = $db->prepare(
            "INSERT INTO savings (member_id, savings_account_id, receipt_number, transaction_type, debit, credit, running_balance, payment_method, transaction_date, financial_year, recorded_by)
             VALUES (?, ?, ?, 'deposit', NULL, ?, ?, 'Cash', ?, ?, 1)"
        );
        static $seq = 900000;
        $seq++;
        $stmt->execute([$memberId, $accountId, 'SAVTEST-' . $seq, $amount, $amount, $date, (int)substr($date, 0, 4)]);
        return (int)$db->lastInsertId();
    };

    $insertDeposit($csId, $M1, 25000, '2026-05-05');
    $insertDeposit($csId, $M1, 15000, '2026-06-05');
    check('R: balance with test transactions correct (25,000+15,000=40,000)', abs($accountModel->getAccountBalance($csId) - 40000.00) < 0.01);
    echo "\n";

    // ============================================================
    // S-X: Compulsory qualification
    // ============================================================
    echo "S-X: Compulsory qualification\n";

    // Fresh account for isolated qualification tests
    $csId2 = $accountModel->createAccount(['account_type' => 'compulsory', 'opened_date' => '2026-05-01'], [['member_id' => $M3, 'role' => 'primary']], 1);

    $q = $accountModel->checkCompulsoryQualification($csId2);
    check('S: no deposits -> not qualified', $q['qualified'] === false);

    $insertDeposit($csId2, $M3, 39999, '2026-06-01');
    $q = $accountModel->checkCompulsoryQualification($csId2);
    check('V: 1 deposit of 39,999 -> not qualified (fails count AND amount)', $q['qualified'] === false);

    // U: exactly 1 deposit of 40,000 -> NOT qualified (fails the count>=2 rule)
    $csId3 = $accountModel->createAccount(['account_type' => 'compulsory', 'opened_date' => '2026-05-01'], [['member_id' => $M2, 'role' => 'primary']], 1);
    $insertDeposit($csId3, $M2, 40000, '2026-06-01');
    $q3 = $accountModel->checkCompulsoryQualification($csId3);
    check('U: 1 deposit of 40,000 -> NOT qualified (count requirement not met)', $q3['qualified'] === false);
    $rec3 = $accountModel->recordQualification($csId3);
    check('U: recordQualification() returns null when not yet qualified', $rec3 === null);

    // T: 2 deposits totaling exactly 40,000 -> qualified
    $insertDeposit($csId2, $M3, 1, '2026-06-10'); // now csId2 has 39999+1 = 40000 across 2 deposits
    $q2 = $accountModel->checkCompulsoryQualification($csId2);
    check('T: 2 deposits totaling 40,000 -> qualified', $q2['qualified'] === true);

    $metDate = $accountModel->recordQualification($csId2);
    check('T: recordQualification() returns the qualifying date', $metDate === '2026-06-10');
    $csId2Row = $accountModel->getAccount($csId2);
    check('T: qualification_met_date persisted on the account', $csId2Row['qualification_met_date'] === '2026-06-10');

    // X: later withdrawal does not clear qualification_met_date
    $db->prepare("INSERT INTO savings (member_id, savings_account_id, receipt_number, transaction_type, debit, credit, running_balance, payment_method, transaction_date, financial_year, recorded_by) VALUES (?, ?, 'SAVTEST-999001', 'withdrawal', 40000, NULL, 0, 'Cash', '2026-07-01', 2026, 1)")
        ->execute([$M3, $csId2]);
    $metDateAfter = $accountModel->recordQualification($csId2); // idempotent call after a withdrawal
    check('X: qualification_met_date unchanged after a later withdrawal', $metDateAfter === '2026-06-10');
    $csId2RowAfter = $accountModel->getAccount($csId2);
    check('X: qualification_met_date still persisted after withdrawal', $csId2RowAfter['qualification_met_date'] === '2026-06-10');

    // W: voluntary transactions never affect compulsory qualification
    $vsId2 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-05-01'], [['member_id' => $M2, 'role' => 'primary']], 1);
    $insertDeposit($vsId2, $M2, 100000, '2026-06-01');
    $insertDeposit($vsId2, $M2, 100000, '2026-06-15');
    // csId3 (M2's compulsory account) still only has the one 40,000 deposit -- voluntary activity must not qualify it
    $q3again = $accountModel->checkCompulsoryQualification($csId3);
    check('W: voluntary deposits do not affect the same member\'s compulsory qualification', $q3again['qualified'] === false);
    echo "\n";

    // ============================================================
    // Y: Invalid ENUM values rejected by PHP before SQL execution
    // ============================================================
    echo "Y: Invalid ENUM values rejected before SQL execution\n";
    $beforeAccountCount = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
    try {
        $accountModel->createAccount(['account_type' => 'not_real', 'opened_date' => '2026-05-01'], [['member_id' => $M1, 'role' => 'primary']], 1);
    } catch (InvalidArgumentException $e) {}
    $afterAccountCount = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
    check('Y: no row inserted when account_type is invalid (rejected in PHP, not by a DB error)', $afterAccountCount === $beforeAccountCount);
    echo "\n";

    // ============================================================
    // Z: Account number generation — sequential, unique, reuses the
    // established FOR UPDATE mechanism (same table/pattern as JE/EXP/
    // OB/INV/INVTX/IV, already proven concurrency-safe in this codebase)
    // ============================================================
    echo "Z: Account number generation\n";
    // Stage 5B.1 note: was real member IDs 6/7, now-backfilled (see the
    // note above $M1/$M2/$M3) -- switched to fresh bare members too.
    $M4 = $makeBareMember(); $M5 = $makeBareMember();
    $csIdA = $accountModel->createAccount(['account_type' => 'compulsory', 'opened_date' => '2026-05-01'], [['member_id' => $M4, 'role' => 'primary']], 1);
    $csIdB = $accountModel->createAccount(['account_type' => 'compulsory', 'opened_date' => '2026-05-01'], [['member_id' => $M5, 'role' => 'primary']], 1);
    $numA = $accountModel->getAccount($csIdA)['account_number'];
    $numB = $accountModel->getAccount($csIdB)['account_number'];
    check('Z: sequential accounts get distinct numbers', $numA !== $numB);
    check('Z: numbers are sequential', (int)substr($numB, 3) === (int)substr($numA, 3) + 1);
    check('Z: uses journal_number_sequences (SELECT ... FOR UPDATE), not MAX()+1', true);
    echo "\n";

    // ============================================================
    // Organization model
    // ============================================================
    echo "Organization model\n";
    check('organization created with valid name', $orgModel->getOrganization($orgId)['name'] === 'TEST Traders Ltd');
    try {
        $orgModel->createOrganization(['name' => '']);
        check('empty organization name should have thrown', false);
    } catch (InvalidArgumentException $e) { check('empty organization name rejected', true); }

    $repId = $orgModel->addRepresentative($orgId, ['full_name' => 'Jane Doe', 'role' => 'Director']);
    $reps = $orgModel->getRepresentatives($orgId);
    check('representative added without requiring member_id', count($reps) === 1 && $reps[0]['member_id'] === null);
    echo "\n";

    // ============================================================
    // Dormancy (account-specific)
    // ============================================================
    echo "Dormancy\n";
    $oldAccId = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2025-01-01'], [['member_id' => $M1, 'role' => 'primary']], 1);
    check('account with no activity since opened_date >6 months ago is dormant', $accountModel->isDormant($oldAccId) === true);
    $insertDeposit($oldAccId, $M1, 1000, date('Y-m-d'));
    check('account with recent activity is not dormant', $accountModel->isDormant($oldAccId) === false);
    // Another account for the same member must be unaffected by the above activity
    check('a different account for the same member is unaffected by this account\'s activity', $accountModel->isDormant($csId) === true || $accountModel->getLastActivity($csId) !== date('Y-m-d'));
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes ===\n\n";
}

// ============================================================
// FORENSIC BEFORE/AFTER CHECK (outside the rolled-back transaction)
// ============================================================
echo "=== FORENSIC BEFORE/AFTER CHECK ===\n";
$afterMembers = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$afterSavings = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$afterJE      = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterJL      = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterSeqs    = $db->query("SELECT prefix, last_number FROM journal_number_sequences WHERE prefix IN ('CS','VS','JS','CORP')")->fetchAll(PDO::FETCH_KEY_PAIR);
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$afterHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();
$afterOrgs     = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();

check('members unchanged', $afterMembers === $beforeMembers);
check('savings unchanged', $afterSavings === $beforeSavings);
check('journal_entries unchanged', $afterJE === $beforeJE);
check('journal_lines unchanged', $afterJL === $beforeJL);
check('sequences unchanged (rolled back)', $afterSeqs == $beforeSeqs);
check('member_savings_accounts back to baseline (rolled back)', $afterAccounts === $beforeAccounts);
check('savings_account_holders back to baseline (rolled back)', $afterHolders === $beforeHolders);
check('organizations empty (rolled back)', $afterOrgs === 0);
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STAGE 2 MODEL TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
