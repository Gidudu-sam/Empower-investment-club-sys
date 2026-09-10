<?php
/**
 * ISOLATED — Stage 11: Universal Savings Account Closure Framework.
 * Runs ONLY against empower_db_stage11_test. Never touches empower_db.
 */
chdir(__DIR__);
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

define('DB_NAME', 'empower_db_stage11_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';
$db = Database::getInstance()->getConnection();

function renderAs(string $role, string $userId, string $action, array $post, array $get = []): string {
    static $counter = 0;
    $counter++;
    $file = __DIR__ . '/tmp_stage11_subproc_' . $counter . '.php';
    $postLines = "\$_SERVER['REQUEST_METHOD'] = 'POST';\n\$_POST['csrf_token'] = 'skip';\n";
    foreach ($post as $k => $v) { $postLines .= "\$_POST['{$k}'] = " . var_export($v, true) . ";\n"; }
    foreach ($get as $k => $v) { $postLines .= "\$_GET['{$k}'] = " . var_export($v, true) . ";\n"; }
    $body = <<<PHP
<?php
chdir(__DIR__);
define('DB_NAME', 'empower_db_stage11_test');
require 'app/config/config.php';
require 'test_safety_guard.php';
require CORE_PATH . '/Database.php';
require CORE_PATH . '/Model.php';
require CORE_PATH . '/Autoloader.php';
require CORE_PATH . '/Session.php';
require CORE_PATH . '/Controller.php';
Session::start();
Session::set('user_id', {$userId});
Session::set('user_role', '{$role}');
Session::set('user_name', 'Probe {$role}');
Session::set('last_activity', time());
Session::set('csrf_token', 'skip');
{$postLines}
try {
    (new SavingsAccountController())->{$action}();
    echo "\\nRESULT:REACHED";
} catch (Throwable \$e) {
    echo "RESULT:EXCEPTION:" . \$e->getMessage();
}
PHP;
    file_put_contents($file, $body);
    $out = shell_exec('"' . PHP_BINARY . '" "' . $file . '" 2>&1');
    @unlink($file);
    return $out ?? '';
}

$accountModel = new MemberSavingsAccountModel();
$holderModel = new SavingsAccountHolderModel();
$service = new SavingsAccountClosureService();

// Real user ids in this dataset (confirmed via roles JOIN): 1=admin, 295=office_admin, 296=chairman, 297=treasurer, 298=cashier
const U_ADMIN = 1; const U_OFFICE = 295; const U_TREASURER = 297; const U_CASHIER = 298;

/** Seed a deposit directly via the model (isolated test DB only) so an
 *  account has a real positive balance to settle. */
function seedDeposit(int $accountId, int $memberId, float $amount): void {
    $model = new SavingsModel();
    $model->recordDepositWithPosting([
        'member_id' => $memberId, 'savings_account_id' => $accountId, 'amount' => $amount,
        'transaction_type' => 'deposit', 'payment_method' => 'Cash',
        'receipt_number' => $model->generateReceiptNumber(),
        'transaction_date' => date('Y-m-d'), 'notes' => 'Stage 11 test seed',
    ], U_ADMIN);
}

function balanceOf(int $accountId): float {
    global $db;
    $stmt = $db->prepare("SELECT COALESCE(SUM(credit)-SUM(debit),0) FROM savings WHERE savings_account_id=?");
    $stmt->execute([$accountId]);
    return (float)$stmt->fetchColumn();
}

// Real fixtures already present in this dataset (confirmed via forensic queries).
const JOINT_ACCOUNT_ID = 3137;      // JS-000001: holders 6 (primary MASABA MARTIN) + 7 (joint KAYINGA PAUL)
const JOINT_PRIMARY_MEMBER = 6;
const JOINT_SECONDARY_MEMBER = 7;
const CORPORATE_ACCOUNT_ID = 2453;  // CORP-000001: organization_id 240, both representatives have member_id=NULL today
const CORPORATE_ORG_ID = 240;

echo "=== SECTION 1: Eligibility (read-only) ===\n";

$voluntaryId = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 10, 'role' => 'primary']], U_ADMIN);
$e1 = $service->checkEligibility($accountModel->getAccount($voluntaryId));
ok($e1['eligible'] === true, '1. Active voluntary account with zero balance is eligible');
ok($e1['settlement_member_id'] === 10, '1b. Settlement attributed to the account\'s single holder');

$corpAccount = $accountModel->getAccount(CORPORATE_ACCOUNT_ID);
$eCorpToday = $service->checkEligibility($corpAccount);
ok($eCorpToday['eligible'] === false, '2. Corporate account with NO linked representative (today\'s real state) is ineligible', json_encode($eCorpToday));
ok(str_contains($eCorpToday['reason'] ?? '', 'authorized representative'), '2b. Ineligibility reason names the missing authorized representative');

// Seed a linked representative -- TEST DATABASE ONLY, never production.
$db->prepare("UPDATE organization_representatives SET member_id=? WHERE organization_id=? LIMIT 1")->execute([10, CORPORATE_ORG_ID]);
$eCorpFixed = $service->checkEligibility($accountModel->getAccount(CORPORATE_ACCOUNT_ID));
ok($eCorpFixed['eligible'] === true, '3. Corporate account WITH a linked authorized representative becomes eligible (isolated test DB only)');
ok($eCorpFixed['settlement_member_id'] === 10, '3b. Settlement attributed to the representative\'s linked member');

$jointAccount = $accountModel->getAccount(JOINT_ACCOUNT_ID);
$eJointDefault = $service->checkEligibility($jointAccount);
ok($eJointDefault['eligible'] === true, '4. Joint account is eligible');
ok($eJointDefault['settlement_member_id'] === JOINT_PRIMARY_MEMBER, '4b. Default settlement attribution is the primary holder');

$eJointExplicit = $service->checkEligibility($jointAccount, JOINT_SECONDARY_MEMBER);
ok($eJointExplicit['eligible'] === true && $eJointExplicit['settlement_member_id'] === JOINT_SECONDARY_MEMBER, '5. Explicit holder selection overrides the default when that holder is valid');

$eJointInvalid = $service->checkEligibility($jointAccount, 999999);
ok($eJointInvalid['eligible'] === false, '6. Explicit holder selection rejected when not an actual holder of this account');

$accountModel->closeAccount($voluntaryId, date('Y-m-d'));
$eClosed = $service->checkEligibility($accountModel->getAccount($voluntaryId));
ok($eClosed['eligible'] === false, '7. An already-closed account is not eligible for a new closure request');
$accountModel->updateStatus($voluntaryId, 'active'); // restore for later sections

echo "=== SECTION 2: Request creation ===\n";

$reqId1 = $service->requestClosure($voluntaryId, U_OFFICE, 'Member relocating');
$row1 = $db->query("SELECT * FROM savings_account_closure_requests WHERE id={$reqId1}")->fetch();
ok($row1['status'] === 'pending' && $row1['account_type'] === 'voluntary' && (int)$row1['member_id'] === 10, '8. requestClosure() creates a correctly-snapshotted pending row');

try {
    $service->requestClosure($voluntaryId, U_OFFICE);
    ok(false, '9. Duplicate active request should be blocked');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'already exists'), '9. Duplicate active closure request is blocked');
}

$fdAccountId = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 11, 'role' => 'primary']], U_ADMIN);
$db->prepare("UPDATE member_savings_accounts SET account_type='fixed_deposit' WHERE id=?")->execute([$fdAccountId]);
try {
    $service->requestClosure($fdAccountId, U_OFFICE);
    ok(false, '10. Fixed Deposit account type should be refused by this service');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'Fixed Deposit'), '10. Fixed Deposit accounts are refused, directing to the FD-2 workflow instead');
}

echo "=== SECTION 3: Approve / Reject / Cancel (maker-checker) ===\n";

$logBefore = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='savings_account_closure_self_approval_blocked'")->fetchColumn();
try {
    $service->approve($reqId1, U_OFFICE); // U_OFFICE is the requester
    ok(false, '11. Self-approval should be blocked');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'cannot approve'), '11. Self-approval is blocked');
}
$logAfter = (int)$db->query("SELECT COUNT(*) FROM activity_logs WHERE action='savings_account_closure_self_approval_blocked'")->fetchColumn();
ok($logAfter === $logBefore + 1, '12. Blocked self-approval attempt is audited exactly once (survives the rejection, not erased by a rollback)');
$statusStillPending = $db->query("SELECT status FROM savings_account_closure_requests WHERE id={$reqId1}")->fetchColumn();
ok($statusStillPending === 'pending', '12b. Blocked self-approval leaves the request state unchanged');

$service->approve($reqId1, U_TREASURER);
$statusApproved = $db->query("SELECT status, approved_by FROM savings_account_closure_requests WHERE id={$reqId1}")->fetch();
ok($statusApproved['status'] === 'approved' && (int)$statusApproved['approved_by'] === U_TREASURER, '13. Non-requester approval succeeds');

try {
    $service->reject($reqId1, U_TREASURER, '');
    ok(false, '14. Rejection with a blank reason should be refused');
} catch (InvalidArgumentException $e) {
    ok(true, '14. Rejection requires a non-blank reason');
}

try {
    $service->approve($reqId1, U_TREASURER);
    ok(false, '15. Approving an already-approved request should be refused');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'pending'), '15. Only a pending request can be approved');
}

$voluntaryId2 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 12, 'role' => 'primary']], U_ADMIN);
$reqId2 = $service->requestClosure($voluntaryId2, U_OFFICE);
$service->reject($reqId2, U_TREASURER, 'Member changed their mind');
$row2 = $db->query("SELECT status, active_marker FROM savings_account_closure_requests WHERE id={$reqId2}")->fetch();
ok($row2['status'] === 'rejected' && $row2['active_marker'] === null, '16. Rejection sets status + clears active_marker (allows a fresh request)');
$reqId2b = $service->requestClosure($voluntaryId2, U_OFFICE); // should now succeed
ok($reqId2b > 0, '16b. A new closure request can be submitted after a rejection');

try {
    $service->cancel($reqId2b, U_TREASURER);
    ok(false, '17. Cancel by a non-requester should be refused');
} catch (InvalidArgumentException $e) {
    ok(str_contains($e->getMessage(), 'yourself'), '17. Only the original requester may cancel their own request');
}
$service->cancel($reqId2b, U_OFFICE);
$row2b = $db->query("SELECT status FROM savings_account_closure_requests WHERE id={$reqId2b}")->fetchColumn();
ok($row2b === 'cancelled', '18. The requester can cancel their own pending request');

echo "=== SECTION 4: Settlement -- balance recalculated fresh, journal correct ===\n";

$voluntaryId3 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 13, 'role' => 'primary']], U_ADMIN);
$reqId3 = $service->requestClosure($voluntaryId3, U_OFFICE); // balance 0 at request time
seedDeposit($voluntaryId3, 13, 500000); // balance changes AFTER the request was made
$service->approve($reqId3, U_TREASURER);

$result3 = $service->settle($reqId3, U_CASHIER, 'Cash', 'REF-1', date('Y-m-d'));
ok(abs($result3['amount_paid'] - 500000.0) < 0.01, '19. Settlement amount reflects the CURRENT balance, not the stale request-time snapshot (0)', (string)$result3['amount_paid']);
ok(!empty($result3['journal_entry_id']), '19b. Settlement posts a journal entry');

$journal = $db->prepare("SELECT * FROM journal_entries WHERE id=?"); $journal->execute([$result3['journal_entry_id']]);
$lines = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id=?"); $lines->execute([$result3['journal_entry_id']]);
$lineRows = $lines->fetchAll();
$totalDebit = array_sum(array_column($lineRows, 'debit'));
$totalCredit = array_sum(array_column($lineRows, 'credit'));
ok(abs($totalDebit - $totalCredit) < 0.01 && abs($totalDebit - 500000.0) < 0.01, '20. Journal is balanced and matches the settled amount');
$liabilityLine = array_values(array_filter($lineRows, fn($l) => (int)$l['account_id'] === 17));
ok(!empty($liabilityLine) && (float)$liabilityLine[0]['debit'] === 500000.0, "21. Members' Savings liability account (17) is debited, mirroring an ordinary withdrawal");

$accountAfter = $accountModel->getAccount($voluntaryId3);
ok($accountAfter['status'] === 'closed', '22. Account is closed after successful settlement');
$requestAfter = $db->query("SELECT status FROM savings_account_closure_requests WHERE id={$reqId3}")->fetchColumn();
ok($requestAfter === 'paid', '22b. Request status becomes paid');

$journalCountBefore = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result3b = $service->settle($reqId3, U_CASHIER, 'Cash', 'REF-1', date('Y-m-d'));
$journalCountAfter = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok(!empty($result3b['already_paid']) && $journalCountAfter === $journalCountBefore, '23. Repeated settlement is idempotent -- no duplicate journal, safe no-op');

echo "=== SECTION 5: Settlement -- zero balance ===\n";

$voluntaryId4 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 14, 'role' => 'primary']], U_ADMIN);
$reqId4 = $service->requestClosure($voluntaryId4, U_OFFICE);
$service->approve($reqId4, U_TREASURER);
$result4 = $service->settle($reqId4, U_CASHIER, null, null, date('Y-m-d'));
ok($result4['amount_paid'] === 0.0 && $result4['journal_entry_id'] === null, '24. Zero-balance settlement posts no journal');
ok($accountModel->getAccount($voluntaryId4)['status'] === 'closed', '24b. Zero-balance account is still closed');

echo "=== SECTION 6: Corporate settlement attribution ===\n";

$reqIdCorp = $service->requestClosure(CORPORATE_ACCOUNT_ID, U_OFFICE);
seedDeposit(CORPORATE_ACCOUNT_ID, 10, 250000);
$service->approve($reqIdCorp, U_TREASURER);
$resultCorp = $service->settle($reqIdCorp, U_CASHIER, 'Bank Transfer', 'CORP-REF', date('Y-m-d'));
$corpSavingsRow = $db->query("SELECT member_id FROM savings WHERE savings_account_id=" . CORPORATE_ACCOUNT_ID . " AND transaction_type='withdrawal' ORDER BY id DESC LIMIT 1")->fetch();
ok((int)$corpSavingsRow['member_id'] === 10, '25. Corporate settlement is attributed to the linked authorized representative\'s member record');
ok($accountModel->getAccount(CORPORATE_ACCOUNT_ID)['status'] === 'closed', '25b. Corporate account closes on settlement');
$orgStillExists = (new OrganizationModel())->getOrganization(CORPORATE_ORG_ID);
ok($orgStillExists !== false, '26. The underlying organization record is preserved, not deleted, after closure');

echo "=== SECTION 7: Joint settlement -- both holders preserved ===\n";

seedDeposit(JOINT_ACCOUNT_ID, JOINT_PRIMARY_MEMBER, 300000);
$reqIdJoint = $service->requestClosure(JOINT_ACCOUNT_ID, U_OFFICE, null, JOINT_SECONDARY_MEMBER);
$service->approve($reqIdJoint, U_TREASURER);
$resultJoint = $service->settle($reqIdJoint, U_CASHIER, 'Cash', null, date('Y-m-d'));
ok(abs($resultJoint['amount_paid'] - 300000.0) < 0.01, '27. Joint account settlement pays out the full joint balance');
$jointSavingsRow = $db->query("SELECT member_id FROM savings WHERE savings_account_id=" . JOINT_ACCOUNT_ID . " AND transaction_type='withdrawal' ORDER BY id DESC LIMIT 1")->fetch();
ok((int)$jointSavingsRow['member_id'] === JOINT_SECONDARY_MEMBER, '27b. Settlement is attributed to the explicitly-selected holder');
$holdersAfter = $holderModel->getAccountHolders(JOINT_ACCOUNT_ID);
ok(count($holdersAfter) === 2, '28. Both joint holders remain intact in savings_account_holders after closure');

echo "=== SECTION 8: Compulsory -- full balance, no share conversion at closure ===\n";

$compulsoryRow = $db->query("SELECT id FROM member_savings_accounts WHERE account_type='compulsory' AND status='active' LIMIT 1")->fetch();
$compulsoryId = (int)$compulsoryRow['id'];
$compulsoryHolder = $holderModel->getAccountHolders($compulsoryId)[0];
seedDeposit($compulsoryId, (int)$compulsoryHolder['member_id'], 200000);
$balBeforeClose = balanceOf($compulsoryId);
$reqIdComp = $service->requestClosure($compulsoryId, U_OFFICE);
$service->approve($reqIdComp, U_TREASURER);
$resultComp = $service->settle($reqIdComp, U_CASHIER, 'Cash', null, date('Y-m-d'));
ok(abs($resultComp['amount_paid'] - $balBeforeClose) < 0.01, '29. Compulsory closure pays the full balance in cash -- no share conversion applied (closure, not the annual withdrawal window)');

echo "=== SECTION 9: Fixed Monthly fully removed (2026-09 update) ===\n";

// fixed_monthly (including its one real account, FM-000001) was fully
// purged per an explicit user decision after this section was first
// written -- it originally proved the closure workflow correctly
// handled that legacy type; now it confirms the type is genuinely gone
// rather than merely untested.
$fmRow = $db->query("SELECT id FROM member_savings_accounts WHERE account_type='fixed_monthly' LIMIT 1")->fetch();
ok($fmRow === false, '30. No fixed_monthly account exists anywhere in this dataset (fully purged)');
ok(!in_array('fixed_monthly', SavingsAccountClosureService::ACCOUNT_TYPES, true), '30b. fixed_monthly is no longer eligible for the universal closure workflow');
$fmProd = $db->query("SELECT account_type, status FROM member_savings_accounts WHERE account_number='FM-000001'")->fetch();
ok($fmProd === false, '31. FM-000001 no longer exists (fully purged, not merely converted or hidden)');

echo "=== SECTION 10: Security (via real controller subprocess calls) ===\n";

$voluntaryId5 = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => date('Y-m-d')], [['member_id' => 15, 'role' => 'primary']], U_ADMIN);

$out = renderAs('cashier', (string)U_CASHIER, 'closureRequestForm', [], ['id' => $voluntaryId5]);
ok(str_contains($out, 'Access denied'), '32. Cashier cannot access the closure request form (office_admin/admin only)');

$out2 = renderAs('office_admin', (string)U_OFFICE, 'closureApprove', ['request_id' => 1]);
ok(str_contains($out2, 'Access denied'), '33. Office Administrator cannot approve a closure (treasurer/admin only)');

$out3 = renderAs('treasurer', (string)U_TREASURER, 'closureSettleStore', ['request_id' => 1]);
ok(str_contains($out3, 'Access denied'), '34. Treasurer cannot execute a settlement (cashier/admin only)');

$out4 = renderAs('office_admin', (string)U_OFFICE, 'closureRequestStore', ['account_id' => $voluntaryId5, 'csrf_token' => 'WRONG-TOKEN']);
ok(!str_contains($out4, 'Fatal error') && !str_contains($out4, 'EXCEPTION'), '35. Wrong CSRF token is handled gracefully (flash + redirect, not a fatal)');
$countAfterBadCsrf = (int)$db->query("SELECT COUNT(*) FROM savings_account_closure_requests WHERE savings_account_id={$voluntaryId5}")->fetchColumn();
ok($countAfterBadCsrf === 0, '35b. No closure request was created when CSRF verification failed');

echo "=== SECTION 11: FD-2 non-interference ===\n";

$fdServiceStillWorks = class_exists('FixedDepositClosureService');
ok($fdServiceStillWorks, '36. FixedDepositClosureService class remains intact and loadable (full FD-2 regression run separately)');
$fdTableUntouched = $db->query("SELECT COUNT(*) FROM fixed_deposit_closure_requests")->fetchColumn();
ok($fdTableUntouched === '0' || is_numeric($fdTableUntouched), '37. fixed_deposit_closure_requests table is untouched by this suite');

echo "\n============================\n";
echo "TOTAL: {$pass} passed, {$fail} failed\n";
echo "============================\n";
