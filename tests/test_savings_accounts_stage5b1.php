<?php
/**
 * Savings Accounts Module — Stage 5B.1 (existing member compulsory
 * account backfill) test suite.
 *
 * Section 0 checks the REAL, already-executed backfill's live state
 * (read-only) -- by the time this suite is run, the production backfill
 * has already happened, so the correct expectation there is "all 18
 * already provisioned, 0 requiring", proving idempotency at the top
 * level. Sections 1-10 exercise the exact same eligibility-detection and
 * account-creation logic backfill_compulsory_accounts.php uses, but
 * against a small set of FRESH, disposable test members created inside
 * a transaction rolled back at the end -- not the real 18 members, which
 * (correctly, by design) no longer have any "missing account" case left
 * to exercise once the real backfill has run. This mirrors every other
 * stage's test convention in this module (Stage 2/3/4/5B all use
 * disposable STAGE-prefixed test members for exactly this reason).
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();
Session::set('user_id', 1);
Session::set('user_role', 'admin');

echo "=== SAVINGS ACCOUNTS STAGE 5B.1 TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

function captureBaseline(PDO $db): array {
    return [
        'members'          => (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn(),
        'savings_count'    => (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn(),
        'savings_total'    => (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn(),
        'savings_id_checksum'     => (string)$db->query('SELECT MD5(GROUP_CONCAT(id ORDER BY id)) FROM savings')->fetchColumn(),
        'savings_content_checksum' => (string)$db->query("SELECT MD5(GROUP_CONCAT(CONCAT(id,':',COALESCE(credit,0),':',COALESCE(debit,0),':',COALESCE(savings_account_id,'NULL'),':',receipt_number,':',member_id,':',transaction_date) ORDER BY id)) FROM savings")->fetchColumn(),
        'savings_account_id_null' => (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL')->fetchColumn(),
        'journal_entries'  => (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn(),
        'accounts'         => (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn(),
        'holders'          => (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn(),
        'organizations'    => (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn(),
        'seq_CS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn(),
        'seq_VS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='VS'")->fetchColumn(),
        'seq_JS'           => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JS'")->fetchColumn(),
        'seq_CORP'         => $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CORP'")->fetchColumn(),
    ];
}

$before = captureBaseline($db);
echo "Baseline (queried live): members={$before['members']} savings={$before['savings_count']} savingsTotal={$before['savings_total']}\n";
echo "member_savings_accounts={$before['accounts']} holders={$before['holders']} journal_entries={$before['journal_entries']} CS seq={$before['seq_CS']}\n\n";

// ============================================================
// SECTION 0 — the REAL backfill's live, already-executed state
// (read-only). Proves idempotency at the top level: since the actual
// production backfill has already run (see the Stage 5B.1 report), the
// correct, expected state now is "all 18 provisioned, 0 requiring".
// ============================================================
echo "SECTION 0: Real backfill script's live state (post-execution)\n";
$dryRunOut = shell_exec('"' . PHP_BINARY . '" backfill_compulsory_accounts.php 2>&1');
check('dry run reports MODE: DRY RUN (no accidental write mode)', str_contains($dryRunOut ?? '', 'MODE: DRY RUN'));
check('reports 18 existing members', str_contains($dryRunOut ?? '', 'Existing members:              18'));
check('reports all 18 already provisioned (real backfill already executed -- idempotency proof)', str_contains($dryRunOut ?? '', 'Already have a compulsory acct: 18'));
check('reports 0 members still requiring an account', str_contains($dryRunOut ?? '', 'Require a compulsory account:   0'));
check('reports member #15 (dormant) among the already-provisioned, not missing', str_contains($dryRunOut ?? '', '#15 EMP0013 NAMATOVU JOVIA MARY'));
check('confirms it performed zero voluntary/joint/corporate creation', str_contains($dryRunOut ?? '', 'Voluntary accounts to create:   0') && str_contains($dryRunOut ?? '', 'Joint accounts to create:       0') && str_contains($dryRunOut ?? '', 'Corporate accounts to create:   0'));
check('live member_savings_accounts count is exactly 18 (compulsory only)', $before['accounts'] === 18);
$liveCompulsory = (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='compulsory'")->fetchColumn();
check('all 18 live accounts are compulsory (0 voluntary/joint/corporate created by the backfill)', $liveCompulsory === 18);
echo "\n";

$db->beginTransaction();

try {
    $accountModel = new MemberSavingsAccountModel();
    $holderModel  = new SavingsAccountHolderModel();
    $memberModel  = new MemberModel();

    // ============================================================
    // Fresh, disposable test members -- 5 with no account (need
    // backfilling) and 1 pre-provisioned (must be correctly skipped),
    // mirroring the real members' mixed before/after states without
    // depending on the real 18 members' now-permanently-provisioned status.
    // ============================================================
    $counter = 960000;
    $makeBareMember = function () use ($memberModel, &$counter) {
        $counter++;
        return $memberModel->create([
            'member_number' => 'STAGE5B1-' . $counter,
            'first_name'    => 'Stage5B1',
            'last_name'     => 'Test' . $counter,
            'gender'        => 'Male',
            'phone'         => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT),
            'national_id'   => 'S5B1ID' . $counter,
            'join_date'     => '2026-06-15',
            'status'        => 'active',
        ]);
    };

    $testMemberIds = [];
    for ($i = 0; $i < 5; $i++) {
        $testMemberIds[] = $makeBareMember();
    }
    // One dormant member, to mirror the real #15 case.
    $dormantId = $memberModel->create([
        'member_number' => 'STAGE5B1-' . (++$counter), 'first_name' => 'Stage5B1', 'last_name' => 'Dormant' . $counter,
        'gender' => 'Female', 'phone' => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT), 'national_id' => 'S5B1ID' . $counter,
        'join_date' => '2026-01-05', 'status' => 'dormant',
    ]);
    $testMemberIds[] = $dormantId;

    // One member already pre-provisioned (must be skipped by the backfill).
    $preProvisionedId = $makeBareMember();
    $preExistingAccountId = $accountModel->createAccount(
        ['account_type' => 'compulsory', 'opened_date' => '2026-06-15'],
        [['member_id' => $preProvisionedId, 'role' => 'primary']], 1
    );

    $allTestMemberIds = array_merge($testMemberIds, [$preProvisionedId]);

    // ============================================================
    // SECTION 1 — Eligibility detection
    // ============================================================
    echo "SECTION 1: Eligibility detection\n";
    $members = array_map(fn($id) => $memberModel->find($id), $allTestMemberIds);
    check('7 test members set up (6 bare + 1 pre-provisioned)', count($members) === 7);

    $eligible = [];
    foreach ($members as $m) {
        $accts = $holderModel->getMemberAccounts((int)$m['id']);
        $hasCompulsory = count(array_filter($accts, fn($a) => $a['account_type'] === 'compulsory')) > 0;
        if (!$hasCompulsory) {
            $eligible[] = $m;
        }
    }
    check('exactly 6 of the 7 test members are eligible (the 7th already has one)', count($eligible) === 6);
    check('the pre-provisioned member is correctly excluded from eligibility', !in_array($preProvisionedId, array_column($eligible, 'id'), true));
    echo "\n";

    // ============================================================
    // SECTION 2 — Creation: exact same call the script makes
    // ============================================================
    echo "SECTION 2: Compulsory account creation\n";
    $created = [];
    foreach ($eligible as $m) {
        $accountId = $accountModel->createAccount(
            ['account_type' => 'compulsory', 'opened_date' => $m['join_date']],
            [['member_id' => (int)$m['id'], 'role' => 'primary']],
            1
        );
        $created[] = ['member' => $m, 'account' => $accountModel->getAccount($accountId)];
    }
    check('6 accounts created', count($created) === 6);
    check('every created account has account_type = compulsory', count(array_filter($created, fn($c) => $c['account']['account_type'] === 'compulsory')) === 6);
    check('every created account has ownership_type = individual', count(array_filter($created, fn($c) => $c['account']['ownership_type'] === 'individual')) === 6);
    check('every created account has status = active (Stage 1/3\'s established default, reused)', count(array_filter($created, fn($c) => $c['account']['status'] === 'active')) === 6);
    check('every created account\'s opened_date = the member\'s own join_date', count(array_filter($created, fn($c) => $c['account']['opened_date'] === $c['member']['join_date'])) === 6);
    check('every created account starts with CS-', count(array_filter($created, fn($c) => str_starts_with($c['account']['account_number'], 'CS-'))) === 6);
    $dormantCreated = array_values(array_filter($created, fn($c) => (int)$c['member']['id'] === $dormantId))[0] ?? null;
    check('the dormant test member was NOT silently skipped -- got a compulsory account like everyone else', $dormantCreated !== null);
    echo "\n";

    // ============================================================
    // SECTION 3 — Holder relationship
    // ============================================================
    echo "SECTION 3: Holder relationships\n";
    $holderOk = true;
    foreach ($created as $c) {
        $holders = $accountModel->getAccountHolders($c['account']['id']);
        if (count($holders) !== 1 || $holders[0]['role'] !== 'primary' || (int)$holders[0]['member_id'] !== (int)$c['member']['id']) {
            $holderOk = false;
            break;
        }
    }
    check('every created account has exactly one primary holder = the correct member', $holderOk);
    echo "\n";

    // ============================================================
    // SECTION 4 — Uniqueness: no member has more than one compulsory account
    // ============================================================
    echo "SECTION 4: Uniqueness\n";
    $dupCheck = $db->query("
        SELECT h.member_id, COUNT(*) c
        FROM savings_account_holders h
        JOIN member_savings_accounts a ON a.id = h.account_id
        WHERE a.account_type = 'compulsory' AND h.member_id IN (" . implode(',', $allTestMemberIds) . ")
        GROUP BY h.member_id HAVING c > 1
    ")->fetchAll();
    check('no test member holds more than one compulsory account', count($dupCheck) === 0);
    $preProvisionedAccounts = $holderModel->getMemberAccounts($preProvisionedId);
    check('the pre-provisioned member still has exactly its original 1 account (untouched)', count(array_filter($preProvisionedAccounts, fn($a) => $a['account_type'] === 'compulsory')) === 1);
    echo "\n";

    // ============================================================
    // SECTION 5 — Idempotency: run the same eligibility+create logic again
    // ============================================================
    echo "SECTION 5: Idempotency (re-running the backfill logic)\n";
    $secondPassEligible = [];
    foreach ($members as $m) {
        $accts = $holderModel->getMemberAccounts((int)$m['id']);
        $hasCompulsory = count(array_filter($accts, fn($a) => $a['account_type'] === 'compulsory')) > 0;
        if (!$hasCompulsory) {
            $secondPassEligible[] = $m;
        }
    }
    check('second pass finds 0 of these 7 test members still requiring an account', count($secondPassEligible) === 0);

    try {
        $accountModel->createAccount(
            ['account_type' => 'compulsory', 'opened_date' => $eligible[0]['join_date']],
            [['member_id' => (int)$eligible[0]['id'], 'role' => 'primary']],
            1
        );
        check('createAccount()\'s own duplicate guard should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('createAccount()\'s own duplicate-compulsory guard independently rejects a forced re-attempt -- ' . $e->getMessage(), true);
    }
    echo "\n";

    // ============================================================
    // SECTION 6 — Numbering: sequential, unique, correctly prefixed
    // ============================================================
    echo "SECTION 6: Account numbering\n";
    $numbers = array_map(fn($c) => (int)substr($c['account']['account_number'], 3), $created);
    sort($numbers);
    $expected = range($numbers[0], $numbers[0] + 5);
    check('6 account numbers are sequential with no gaps', $numbers === $expected);
    check('all 6 account numbers are unique', count(array_unique($numbers)) === 6);
    $seqNow = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='CS'")->fetchColumn();
    // +1 for the pre-provisioned member's account created above, +6 for this section's batch.
    check('CS sequence advanced by exactly 7 (1 pre-provisioned setup + 6 backfilled)', (int)$seqNow === (int)$before['seq_CS'] + 7);
    echo "\n";

    // ============================================================
    // SECTION 7 — Accounting isolation: no journal entries from account creation
    // ============================================================
    echo "SECTION 7: Accounting isolation\n";
    $jeNow = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('journal_entries count unchanged by creating 7 accounts', $jeNow === $before['journal_entries']);
    echo "\n";

    // ============================================================
    // SECTION 8 — Voluntary / joint / corporate protection
    // ============================================================
    echo "SECTION 8: Voluntary/joint/corporate protection\n";
    $volCount = (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='voluntary' AND id > {$before['accounts']}")->fetchColumn();
    $jointCount = (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='joint' AND id > {$before['accounts']}")->fetchColumn();
    $corpCount = (int)$db->query("SELECT COUNT(*) FROM member_savings_accounts WHERE account_type='corporate' AND id > {$before['accounts']}")->fetchColumn();
    check('0 voluntary accounts created by this test run', $volCount === 0);
    check('0 joint accounts created by this test run', $jointCount === 0);
    check('0 corporate accounts created by this test run', $corpCount === 0);
    echo "\n";

    // ============================================================
    // SECTION 9 — Historical Savings untouched (mid-transaction)
    // ============================================================
    echo "SECTION 9: Historical Savings untouched (mid-transaction)\n";
    $midSavingsCount = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    $midNullCount = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NULL')->fetchColumn();
    $midTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
    check('savings row count still matches baseline', $midSavingsCount === $before['savings_count']);
    check('all historical NULL-account rows still NULL (none auto-attached)', $midNullCount === $before['savings_account_id_null']);
    check('historical total unchanged', abs($midTotal - $before['savings_total']) < 0.01);
    echo "\n";

    // ============================================================
    // SECTION 10 — Live workflow smoke test: one newly-created account
    // can pass through the Stage 5B deposit workflow.
    // ============================================================
    echo "SECTION 10: Live Stage 5B workflow smoke test\n";
    $savingsModel = new SavingsModel();
    $testAccount = $created[0]['account'];
    $testMember = $created[0]['member'];
    $dep = $savingsModel->recordDepositWithPosting([
        'member_id' => (int)$testMember['id'], 'savings_account_id' => $testAccount['id'],
        'amount' => 15000, 'payment_method' => 'Cash', 'transaction_date' => '2026-08-26',
        'receipt_number' => $savingsModel->generateReceiptNumber(), 'recorded_by' => 1, 'financial_year' => 2026,
    ], 1);
    check('a newly-backfilled compulsory account can receive a deposit through Stage 5B\'s workflow', !empty($dep['id']));
    check('deposit posted a balanced journal entry', !empty($dep['journal_entry_id']));
    check('account balance reflects the test deposit', $accountModel->getAccountBalance($testAccount['id']) === 15000.0);
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes from this test run ===\n\n";
}

// ============================================================
// SECTION 11 — Regressions
// ============================================================
echo "SECTION 11: Regression suites\n";
$stage2Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage2.php 2>&1');
check('Stage 2 model suite (53/53) still passes', str_contains($stage2Out ?? '', 'ALL STAGE 2 MODEL TESTS PASSED'));
$stage3Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage3.php 2>&1');
check('Stage 3 suite (46/46) still passes', str_contains($stage3Out ?? '', 'ALL STAGE 3 TESTS PASSED'));
$stage4Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage4.php 2>&1');
check('Stage 4 suite (55/55) still passes', str_contains($stage4Out ?? '', 'ALL STAGE 4 TESTS PASSED'));
$stage5aOut = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5a.php 2>&1');
check('Stage 5A read-only audit still reports NO DATABASE MUTATIONS DETECTED', str_contains($stage5aOut ?? '', 'NO DATABASE MUTATIONS DETECTED'));
$stage5bOut = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage5b.php 2>&1');
check('Stage 5B suite (63/63) still passes', str_contains($stage5bOut ?? '', 'ALL STAGE 5B TESTS PASSED'));
echo "\n";

// ============================================================
// FORENSIC BASELINE CHECK — outside the rolled-back transaction
// ============================================================
echo "=== FORENSIC BASELINE CHECK ===\n";
$after = captureBaseline($db);
check('members count unchanged', $after['members'] === $before['members']);
check('savings count unchanged', $after['savings_count'] === $before['savings_count']);
check('savings total unchanged', abs($after['savings_total'] - $before['savings_total']) < 0.01);
check('savings row id checksum unchanged', $after['savings_id_checksum'] === $before['savings_id_checksum']);
check('savings row content checksum unchanged', $after['savings_content_checksum'] === $before['savings_content_checksum']);
check('savings.savings_account_id NULL count unchanged (44)', $after['savings_account_id_null'] === $before['savings_account_id_null']);
check('journal_entries count unchanged', $after['journal_entries'] === $before['journal_entries']);
check('member_savings_accounts back to baseline (18, rolled back)', $after['accounts'] === $before['accounts']);
check('savings_account_holders back to baseline (rolled back)', $after['holders'] === $before['holders']);
check('organizations unchanged (rolled back)', $after['organizations'] === $before['organizations']);
foreach (['CS', 'VS', 'JS', 'CORP'] as $p) {
    check("{$p} sequence restored to baseline (rollback undid this run's increments)", (string)$after["seq_{$p}"] === (string)$before["seq_{$p}"]);
}
$savingsAccountsExists = $db->query("SHOW TABLES LIKE 'savings_accounts'")->fetch();
check('orphaned savings_accounts table still exists, untouched', $savingsAccountsExists !== false);
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STAGE 5B.1 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
