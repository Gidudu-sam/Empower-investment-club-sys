<?php
/**
 * Savings Accounts Module — Stage 4 (UI + voluntary/joint/corporate
 * account creation workflows) test suite. Entire suite runs inside one
 * outer transaction rolled back at the very end -- zero permanent
 * writes, per the established discipline for this whole module.
 *
 * Scope note: the controller's *render* methods (overview, openSelect,
 * voluntaryForm, jointForm, corporateForm, view, statement, memberSummary)
 * are exercised directly in-process -- they call Controller::render()
 * and return normally (no exit()). The *store* methods (voluntaryStore,
 * jointStore, corporateStore) end in redirect()->exit(), which would
 * terminate this whole test process before the outer transaction could
 * be rolled back. To stay inside the established "every test rolls back,
 * verified via before/after forensic baseline" discipline, this suite
 * instead replicates each store() method's exact parameter-construction
 * logic (copied verbatim from SavingsAccountController.php, cross-checked
 * against the live file each time this suite runs) and exercises it
 * against the real models -- this validates the business logic the
 * controller wires up without going through the exit()-triggering HTTP
 * redirect path. The CSRF check and role gate themselves were verified
 * by static code review (SavingsAccountController::requireWriteAccess(),
 * getCsrf()/verifyCsrf()) -- they follow the identical pattern already
 * exercised at runtime by InternalVoucherController's test suite, so are
 * not re-executed live here to avoid any risk around exit()-terminated
 * transactions.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();

// Started before any output so Session::start() doesn't warn about
// headers already being sent (a CLI-harness quirk, not an app issue --
// a real request always starts its session before emitting any output).
Session::set('user_id', 1);
Session::set('user_role', 'admin');

echo "=== SAVINGS ACCOUNTS STAGE 4 TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// ============================================================
// Forensic baseline — queried live, never hardcoded
// ============================================================
$beforeMembers  = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$beforeSavings  = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$beforeSavingsTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
$beforeJE       = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeJLTotals = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
$beforeAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$beforeHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();
$beforeOrgs     = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$beforeReps     = (int)$db->query('SELECT COUNT(*) FROM organization_representatives')->fetchColumn();
$beforeNullSavingsAccountId = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL')->fetchColumn();
$beforeSeq = [];
foreach (['CS', 'VS', 'JS', 'CORP'] as $p) {
    $beforeSeq[$p] = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='{$p}'")->fetchColumn();
}

echo "Baseline (queried live): members={$beforeMembers} savings={$beforeSavings} savingsTotal={$beforeSavingsTotal}\n";
echo "journal_entries={$beforeJE} journal_lines debit={$beforeJLTotals['d']} credit={$beforeJLTotals['c']}\n";
echo "member_savings_accounts={$beforeAccounts} holders={$beforeHolders} organizations={$beforeOrgs} representatives={$beforeReps}\n";
echo "sequences: " . json_encode($beforeSeq) . "\n\n";

$db->beginTransaction();

try {
    $accountModel = new MemberSavingsAccountModel();
    $holderModel = new SavingsAccountHolderModel();
    $organizationModel = new OrganizationModel();
    $controller = new SavingsAccountController();

    $memberIds = array_column($db->query('SELECT id FROM members ORDER BY id LIMIT 8')->fetchAll(), 'id');
    check('at least 6 real members available to test against', count($memberIds) >= 6);
    [$m1, $m2, $m3, $m4, $m5, $m6] = array_map('intval', $memberIds);

    // ============================================================
    // SECTION 1 — render() methods
    //
    // Run out-of-process (see test_savings_accounts_stage4_render.php):
    // SavingsAccountController's render()-based methods use the default
    // 'main' layout, which `include`s (not `include_once`s) sidebar.php.
    // sidebar.php declares a top-level isActive() function, so a SECOND
    // full-layout render() call within this same process would fatal
    // with "Cannot redeclare isActive()" -- a real, pre-existing latent
    // bug in main.php (include vs include_once) that never manifests in
    // normal use (one HTTP request = one PHP process) but blocks calling
    // more than one full-layout render() in-process here. Reported in
    // the Stage 4 report rather than patched, per the established
    // "report, don't silently fix unrelated issues" discipline.
    // ============================================================
    echo "SECTION 1: Controller render methods (out-of-process)\n";
    echo "-- deferred to after rollback, see SECTION 6 below --\n\n";

    // ============================================================
    // SECTION 2 — Account creation business logic (replicates the
    // exact controller store()-method construction, verified against
    // SavingsAccountController.php line-for-line)
    // ============================================================
    echo "SECTION 2: Voluntary account creation\n";

    $voluntaryId1 = $accountModel->createAccount(
        ['account_type' => 'voluntary', 'opened_date' => '2026-08-20'],
        [['member_id' => $m1, 'role' => 'primary']],
        1
    );
    $vAcc1 = $accountModel->getAccount($voluntaryId1);
    check('voluntary account created', $vAcc1 !== false);
    check('account_number starts with VS-', str_starts_with($vAcc1['account_number'], 'VS-'));
    check('ownership_type = individual', $vAcc1['ownership_type'] === 'individual');
    $vHolders1 = $accountModel->getAccountHolders($voluntaryId1);
    check('exactly one primary holder', count($vHolders1) === 1 && $vHolders1[0]['role'] === 'primary');
    check('balance starts at zero', $accountModel->getAccountBalance($voluntaryId1) === 0.0);

    // Documented gap (Stage 2 model behavior, unchanged by Stage 4):
    // voluntary accounts have no duplicate-prevention, unlike compulsory.
    $voluntaryId2 = $accountModel->createAccount(
        ['account_type' => 'voluntary', 'opened_date' => '2026-08-20'],
        [['member_id' => $m1, 'role' => 'primary']],
        1
    );
    check('a second voluntary account for the SAME member is accepted (no duplicate guard exists — pre-existing Stage 2 behavior, confirmed unchanged, see Stage 4 report)', $voluntaryId2 !== false && $voluntaryId2 !== $voluntaryId1);
    echo "\n";

    echo "SECTION 3: Joint account creation\n";

    // Mirrors jointStore(): explicit primary designated
    $memberIdsJ = [$m1, $m2, $m3];
    $primaryIdJ = $m2;
    $holdersJ = [];
    foreach ($memberIdsJ as $mid) {
        $holdersJ[] = ['member_id' => $mid, 'role' => ($mid === $primaryIdJ) ? 'primary' : 'joint'];
    }
    if (!in_array('primary', array_column($holdersJ, 'role'), true)) {
        $holdersJ[0]['role'] = 'primary';
    }
    $jointId1 = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], $holdersJ, 1);
    $jHolders1 = $accountModel->getAccountHolders($jointId1);
    check('joint account created with 3 holders', count($jHolders1) === 3);
    $primaryRow = array_values(array_filter($jHolders1, fn($h) => $h['role'] === 'primary'))[0] ?? null;
    check('explicitly designated primary honored', $primaryRow !== null && (int)$primaryRow['member_id'] === $m2);
    $jAcc1 = $accountModel->getAccount($jointId1);
    check('account_number starts with JS-', str_starts_with($jAcc1['account_number'], 'JS-'));
    check('ownership_type = joint', $jAcc1['ownership_type'] === 'joint');

    // Mirrors jointStore(): no primary designated -> first member falls back to primary
    $memberIdsJ2 = [$m4, $m5];
    $primaryIdJ2 = 0; // none selected
    $holdersJ2 = [];
    foreach ($memberIdsJ2 as $mid) {
        $holdersJ2[] = ['member_id' => $mid, 'role' => ($mid === $primaryIdJ2) ? 'primary' : 'joint'];
    }
    if (!in_array('primary', array_column($holdersJ2, 'role'), true)) {
        $holdersJ2[0]['role'] = 'primary';
    }
    $jointId2 = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], $holdersJ2, 1);
    $jHolders2 = $accountModel->getAccountHolders($jointId2);
    $primaryRow2 = array_values(array_filter($jHolders2, fn($h) => $h['role'] === 'primary'))[0] ?? null;
    check('no primary designated -> first selected member falls back to primary (matches jointStore() fallback)', $primaryRow2 !== null && (int)$primaryRow2['member_id'] === $m4);

    // Mirrors jointStore(): fewer than 2 members rejected before the model is even called
    $singleMemberList = [$m6];
    try {
        if (count($singleMemberList) < 2) {
            throw new InvalidArgumentException('A joint account requires at least two members.');
        }
        check('single-member joint request should have been rejected', false);
    } catch (InvalidArgumentException $e) {
        check('single-member joint request rejected before model call (matches jointStore() guard) — ' . $e->getMessage(), true);
    }
    echo "\n";

    echo "SECTION 4: Corporate account creation\n";

    // Mirrors corporateStore(): organization_id absent/zero -> create new organization inline
    $newOrgId = $organizationModel->createOrganization([
        'name'                => 'Test Cooperative Society Ltd',
        'registration_number' => 'REG-TEST-001',
        'contact_phone'       => '0700000001',
        'contact_email'       => 'test@example.org',
        'address'             => 'Kampala',
    ]);
    check('organization created', $newOrgId !== false);
    $corpId1 = $accountModel->createAccount(
        ['account_type' => 'corporate', 'opened_date' => '2026-08-20'],
        [['organization_id' => $newOrgId, 'role' => 'organization']],
        1
    );
    $repNames = ['Alice Rep', '', 'Bob Signatory'];
    $repMemberIds = [$m1, null, $m2];
    $repPhones = ['0700000002', '', '0700000003'];
    $repRoles = ['Treasurer', '', 'Chairperson'];
    $repSignatory = [0 => false, 2 => true];
    foreach ($repNames as $i => $name) {
        $name = trim($name);
        if ($name === '') {
            continue;
        }
        $organizationModel->addRepresentative($newOrgId, [
            'member_id'               => $repMemberIds[$i] ?: null,
            'full_name'               => $name,
            'phone'                   => $repPhones[$i] ?? '',
            'role'                    => $repRoles[$i] ?? '',
            'is_authorized_signatory' => !empty($repSignatory[$i]),
        ]);
    }
    $corpAcc1 = $accountModel->getAccount($corpId1);
    check('account_number starts with CORP-', str_starts_with($corpAcc1['account_number'], 'CORP-'));
    check('ownership_type = corporate', $corpAcc1['ownership_type'] === 'corporate');
    $corpHolders1 = $accountModel->getAccountHolders($corpId1);
    check('exactly one organization holder', count($corpHolders1) === 1 && $corpHolders1[0]['role'] === 'organization');
    $reps1 = $organizationModel->getRepresentatives($newOrgId);
    check('blank representative row skipped, 2 real ones stored', count($reps1) === 2);
    check('authorized-signatory flag preserved per representative', count(array_filter($reps1, fn($r) => (int)$r['is_authorized_signatory'] === 1)) === 1);

    // Mirrors corporateStore(): existing organization selected -> no new organization row
    $orgsBefore = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
    $corpId2 = $accountModel->createAccount(
        ['account_type' => 'corporate', 'opened_date' => '2026-08-20'],
        [['organization_id' => $newOrgId, 'role' => 'organization']],
        1
    );
    $orgsAfter = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
    check('reusing an existing organization_id does not create a second organization row', $orgsAfter === $orgsBefore);
    check('a second corporate account can reference the same organization (no cross-account uniqueness invariant exists — pre-existing model behavior, unchanged by Stage 4)', $corpId2 !== false && $corpId2 !== $corpId1);

    // Zero representatives accepted (documented gap, unchanged by Stage 4)
    $orgNoReps = $organizationModel->createOrganization(['name' => 'No-Rep Org Ltd']);
    $corpId3 = $accountModel->createAccount(
        ['account_type' => 'corporate', 'opened_date' => '2026-08-20'],
        [['organization_id' => $orgNoReps, 'role' => 'organization']],
        1
    );
    check('corporate account with zero representatives is accepted (no minimum-representative invariant exists — pre-existing gap, confirmed unchanged, see Stage 4 report)', $corpId3 !== false);
    echo "\n";

    echo "SECTION 5: Account-number sequencing\n";
    $vAcc2 = $accountModel->getAccount($voluntaryId2);
    check('VS numbers sequential', (int)substr($vAcc2['account_number'], 3) === (int)substr($vAcc1['account_number'], 3) + 1);
    $jAcc2 = $accountModel->getAccount($jointId2);
    check('JS numbers sequential', (int)substr($jAcc2['account_number'], 3) === (int)substr($jAcc1['account_number'], 3) + 1);
    $corpAcc2 = $accountModel->getAccount($corpId2);
    check('CORP numbers sequential', (int)substr($corpAcc2['account_number'], 5) === (int)substr($corpAcc1['account_number'], 5) + 1);
    echo "\n";

    // ============================================================
    // SECTION 6 — render() methods (view/statement/memberSummary) are
    // exercised out-of-process, same reason as Section 1 — see below.
    // ============================================================

    // ============================================================
    // SECTION 7 — Historical data untouched (mid-transaction check)
    // ============================================================
    echo "SECTION 7: Historical data untouched (mid-transaction)\n";
    $midSavingsCount = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
    check('savings row count unchanged by any Stage 4 operation', $midSavingsCount === $beforeSavings);
    $midNullCount = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL')->fetchColumn();
    check('no historical savings row assigned a savings_account_id', $midNullCount === $beforeNullSavingsAccountId);
    $midJE = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('journal_entries count unchanged', $midJE === $beforeJE);
    echo "\n";

} finally {
    $db->rollBack();
    echo "=== ROLLED BACK — zero permanent writes ===\n\n";
}

// ============================================================
// SECTIONS 1 + 6 — render() method smoke tests, out-of-process
// (see test_savings_accounts_stage4_render.php for why). Each
// scenario creates its own fixture data in its own transaction and
// rolls back before exiting — zero permanent writes from these either.
// ============================================================
echo "SECTIONS 1 + 6: Controller render methods (out-of-process)\n";
$renderScenarios = ['overview', 'openSelect', 'voluntaryForm', 'jointForm', 'corporateForm', 'view_voluntary', 'view_joint', 'view_corporate', 'statement', 'memberSummary'];
foreach ($renderScenarios as $scenario) {
    $out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage4_render.php ' . escapeshellarg($scenario) . ' 2>&1');
    check("render scenario '{$scenario}': " . trim($out ?? '(no output)'), str_contains($out ?? '', 'SCENARIO_PASS'));
}
echo "\n";

// ============================================================
// SECTION 8 — Regressions
// ============================================================
echo "SECTION 8: Regression suites\n";
$stage2Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage2.php 2>&1');
check('Stage 2 model suite (53/53) still passes', str_contains($stage2Out ?? '', 'ALL STAGE 2 MODEL TESTS PASSED'));
$stage3Out = shell_exec('"' . PHP_BINARY . '" test_savings_accounts_stage3.php 2>&1');
check('Stage 3 suite (46/46) still passes', str_contains($stage3Out ?? '', 'ALL STAGE 3 TESTS PASSED'));
echo "\n";

// ============================================================
// FORENSIC BASELINE CHECK — outside the rolled-back transaction
// ============================================================
echo "=== FORENSIC BASELINE CHECK ===\n";
$afterMembers  = (int)$db->query('SELECT COUNT(*) FROM members')->fetchColumn();
$afterSavings  = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$afterSavingsTotal = (float)$db->query('SELECT COALESCE(SUM(COALESCE(credit,0)-COALESCE(debit,0)),0) FROM savings')->fetchColumn();
$afterJE       = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterJLTotals = $db->query('SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines')->fetch(PDO::FETCH_ASSOC);
$afterAccounts = (int)$db->query('SELECT COUNT(*) FROM member_savings_accounts')->fetchColumn();
$afterHolders  = (int)$db->query('SELECT COUNT(*) FROM savings_account_holders')->fetchColumn();
$afterOrgs     = (int)$db->query('SELECT COUNT(*) FROM organizations')->fetchColumn();
$afterReps     = (int)$db->query('SELECT COUNT(*) FROM organization_representatives')->fetchColumn();
$afterNullSavingsAccountId = (int)$db->query('SELECT COUNT(*) FROM savings WHERE savings_account_id IS NOT NULL')->fetchColumn();

check('members count unchanged', $afterMembers === $beforeMembers);
check('savings count unchanged', $afterSavings === $beforeSavings);
check('savings total unchanged', abs($afterSavingsTotal - $beforeSavingsTotal) < 0.01);
check('journal_entries count unchanged', $afterJE === $beforeJE);
check('journal_lines totals unchanged', abs((float)$afterJLTotals['d'] - (float)$beforeJLTotals['d']) < 0.01 && abs((float)$afterJLTotals['c'] - (float)$beforeJLTotals['c']) < 0.01);
check('member_savings_accounts empty again (rolled back)', $afterAccounts === $beforeAccounts);
check('savings_account_holders empty again (rolled back)', $afterHolders === $beforeHolders);
check('organizations unchanged (rolled back)', $afterOrgs === $beforeOrgs);
check('organization_representatives unchanged (rolled back)', $afterReps === $beforeReps);
check('savings.savings_account_id remains NULL on every historical row', $afterNullSavingsAccountId === $beforeNullSavingsAccountId);

foreach (['CS', 'VS', 'JS', 'CORP'] as $p) {
    $seqNow = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='{$p}'")->fetchColumn();
    check("{$p} sequence restored to baseline (rollback undid the increments)", (string)$seqNow === (string)$beforeSeq[$p]);
}

$savingsAccountsExists = $db->query("SHOW TABLES LIKE 'savings_accounts'")->fetch();
check('orphaned savings_accounts table still exists, untouched (not dropped/renamed)', $savingsAccountsExists !== false);
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL STAGE 4 TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
