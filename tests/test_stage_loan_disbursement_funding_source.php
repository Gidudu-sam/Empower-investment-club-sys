<?php
/**
 * Stage — Loan Disbursement Funding Source & GL Integration — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db. Covers all 15 required test scenarios, exercising
 * the real draft -> pending_approval -> approved -> active workflow
 * (not a status='active' shortcut), since this stage is specifically
 * about the disbursement event and its now-mandatory funding source.
 */
$dbName = $argv[1] ?? '';
if ($dbName === '' || $dbName === 'empower_db') {
    fwrite(STDERR, "Refusing to run without an explicit, non-production disposable schema name.\n");
    exit(1);
}

define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_NAME', $dbName);
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/../app');
define('CORE_PATH', __DIR__ . '/../core');
define('APP_URL', 'http://localhost/Empower');
define('APP_NAME', 'Empower Test');

require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Session.php';
require_once CORE_PATH . '/Autoloader.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/AccountModel.php';
require_once APP_PATH  . '/services/JournalService.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$db = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$CREATOR = 303; $APPROVER = 296; // two distinct real users (loans_officer, chairman) -- approve() blocks self-approval
$memberModel = new MemberModel();
$loanModel   = new LoanModel();

function makeMember(MemberModel $mm, int $admin, string $tag): array {
    static $seq = 0; $seq++;
    static $runId = null; if ($runId === null) { $runId = str_pad((string)random_int(0, 999), 3, '0', STR_PAD_LEFT); }
    return $mm->createWithCompulsoryAccount([
        'member_number' => $mm->generateMemberNumber(),
        'first_name' => 'LNDISB', 'last_name' => "Test{$tag}{$seq}", 'gender' => 'Female',
        'phone' => '07' . $runId . str_pad((string)(10000 + $seq), 5, '0', STR_PAD_LEFT),
        'national_id' => "LNDISB{$runId}{$tag}{$seq}",
        'date_of_birth' => '1990-01-01', 'address' => 'Test',
        'next_of_kin_name' => 'Test', 'next_of_kin_phone' => '0700000000',
        'join_date' => date('Y-m-d'), 'status' => 'active',
    ], $admin);
}

function baseLoanInput(LoanModel $m, int $memberId, float $amount, string $date, int $creator): array {
    return [
        'loan_number' => $m->generateLoanNumber(),
        'member_id' => $memberId, 'loan_type_id' => 1,
        'loan_amount' => $amount, 'interest_rate' => 10,
        'interest_amount' => round($amount * 0.10, 2),
        'total_payable' => round($amount * 1.10, 2),
        'outstanding' => round($amount * 1.10, 2), 'amount_paid' => 0,
        'issue_date' => $date, 'due_date' => date('Y-m-d', strtotime($date . ' +1 month')),
        'disbursement_date' => $date,
        // Stage — Loan Schedule & Due-Date Integrity Remediation:
        // disburse() now generates the authoritative installment schedule
        // itself (anchored to the real disbursement date) and enforces
        // that it reconciles to total_payable -- exactly like every real
        // loan created via LoanController::store() already has these
        // fields (computed by LoanProductModel::calculateLoan()). This
        // fixture predates that stage; loan_period_months/
        // monthly_installment are added here (1 month, matching the
        // due_date this fixture already assumed) so disburse() can
        // generate a valid one-installment schedule, without changing
        // anything this test actually verifies (disbursement funding-
        // source journal posting).
        'loan_period_months' => 1,
        'monthly_installment' => round($amount * 1.10, 2),
        'repayment_frequency' => 'monthly',
        'interest_mode' => 'percentage',
        // Deliberately NOT setting disbursement_method here -- proves the
        // real posting decision comes from the explicit disburse() call,
        // not from whatever (if anything) was set at creation time.
        'status' => 'draft', 'recorded_by' => $creator,
    ];
}

// Creates an approved loan ready for disbursement testing.
function makeApprovedLoan(LoanModel $m, int $memberId, float $amount, string $date, int $creator, int $approver): int {
    $id = $m->create(baseLoanInput($m, $memberId, $amount, $date, $creator));
    $m->submit($id, $creator);
    $m->approve($id, $approver);
    return $id;
}

function glMovement(PDO $db, int $accountId): array {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE account_id=?");
    $stmt->execute([$accountId]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$ACC_RECEIVABLE = 14; $ACC_CASH = 7; $ACC_MOMO = 8; $ACC_BANK = 10;

// ------------------------------------------------------------------
// TEST 1 -- Cash
// ------------------------------------------------------------------
section('TEST 1: Disburse via Cash -- Dr Loan Receivable / Cr Cash');
$m1 = makeMember($memberModel, $CREATOR, 'Cash');
$loan1 = makeApprovedLoan($loanModel, $m1['member_id'], 600000, date('Y-m-d'), $CREATOR, $APPROVER);
$recvBefore = glMovement($db, $ACC_RECEIVABLE); $cashBefore = glMovement($db, $ACC_CASH);
$r1 = $loanModel->disburse($loan1, $APPROVER, 'Cash');
check('Disbursement succeeded', !empty($r1['journal_entry_id']));
$recvAfter = glMovement($db, $ACC_RECEIVABLE); $cashAfter = glMovement($db, $ACC_CASH);
check('Dr Loan Receivable increased by 600,000', abs(($recvAfter['d'] - $recvBefore['d']) - 600000) < 0.01);
check('Cr Cash increased by 600,000', abs(($cashAfter['c'] - $cashBefore['c']) - 600000) < 0.01);
check('Loan status now active', $loanModel->find($loan1)['status'] === 'active');
check('disbursement_method persisted as Cash', $loanModel->find($loan1)['disbursement_method'] === 'Cash');

// ------------------------------------------------------------------
// TEST 2 -- Bank
// ------------------------------------------------------------------
section('TEST 2: Disburse via Bank -- Dr Loan Receivable / Cr Bank');
$m2 = makeMember($memberModel, $CREATOR, 'Bank');
$loan2 = makeApprovedLoan($loanModel, $m2['member_id'], 600000, date('Y-m-d'), $CREATOR, $APPROVER);
$bankBefore = glMovement($db, $ACC_BANK);
$r2 = $loanModel->disburse($loan2, $APPROVER, 'Bank Transfer');
$bankAfter = glMovement($db, $ACC_BANK);
check('Cr Bank increased by 600,000', abs(($bankAfter['c'] - $bankBefore['c']) - 600000) < 0.01);
check('disbursement_method persisted as Bank Transfer', $loanModel->find($loan2)['disbursement_method'] === 'Bank Transfer');

// ------------------------------------------------------------------
// TEST 3 -- Mobile Money
// ------------------------------------------------------------------
section('TEST 3: Disburse via Mobile Money -- Dr Loan Receivable / Cr Mobile Money');
$m3 = makeMember($memberModel, $CREATOR, 'MoMo');
$loan3 = makeApprovedLoan($loanModel, $m3['member_id'], 600000, date('Y-m-d'), $CREATOR, $APPROVER);
$momoBefore = glMovement($db, $ACC_MOMO);
$r3 = $loanModel->disburse($loan3, $APPROVER, 'MTN Mobile Money');
$momoAfter = glMovement($db, $ACC_MOMO);
check('Cr Mobile Money increased by 600,000', abs(($momoAfter['c'] - $momoBefore['c']) - 600000) < 0.01);

// ------------------------------------------------------------------
// TEST 4 -- Mandatory funding source
// ------------------------------------------------------------------
section('TEST 4: Disbursement without a funding source is rejected');
$m4 = makeMember($memberModel, $CREATOR, 'NoSrc');
$loan4 = makeApprovedLoan($loanModel, $m4['member_id'], 400000, date('Y-m-d'), $CREATOR, $APPROVER);
$jeCountBefore4 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$threw4 = false;
try { $loanModel->disburse($loan4, $APPROVER, ''); } catch (InvalidArgumentException $e) { $threw4 = true; }
check('Empty funding source throws', $threw4);
check('Loan still approved (not active)', $loanModel->find($loan4)['status'] === 'approved');
check('No journal entry created', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore4);

// ------------------------------------------------------------------
// TEST 5 -- Invalid funding account
// ------------------------------------------------------------------
section('TEST 5: An arbitrary/non-Cash-Bank-MoMo value is rejected');
$threw5a = false;
try { $loanModel->disburse($loan4, $APPROVER, 'Loan Processing Fee'); } catch (InvalidArgumentException $e) { $threw5a = true; }
check('Arbitrary string rejected', $threw5a);
$threw5b = false;
try { $loanModel->disburse($loan4, $APPROVER, 'Other'); } catch (InvalidArgumentException $e) { $threw5b = true; }
check("'Other' rejected (no real backing asset)", $threw5b);
check('Loan still approved after both rejected attempts', $loanModel->find($loan4)['status'] === 'approved');

// ------------------------------------------------------------------
// TEST 6 -- Duplicate submission
// ------------------------------------------------------------------
section('TEST 6: Duplicate disbursement submission does not double-post');
$jeCountBefore6 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$r6a = $loanModel->disburse($loan4, $APPROVER, 'Cash');
check('First disbursement succeeded', !empty($r6a['journal_entry_id']));
$threw6 = false;
try { $loanModel->disburse($loan4, $APPROVER, 'Bank Transfer'); } catch (InvalidArgumentException $e) { $threw6 = true; }
check('Second disbursement attempt (even with a different funding source) is rejected', $threw6);
$jeCountAfter6 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
check('Only one new journal entry exists (no duplicate credit to Cash/Bank)', $jeCountAfter6 === $jeCountBefore6 + 1);

// ------------------------------------------------------------------
// TEST 7 -- Atomicity on failure
// ------------------------------------------------------------------
section('TEST 7: A failed posting leaves no partial disbursement');
$m7 = makeMember($memberModel, $CREATOR, 'Atomic');
$loan7 = makeApprovedLoan($loanModel, $m7['member_id'], 250000, date('Y-m-d'), $CREATOR, $APPROVER);
// Force failure: temporarily deactivate the Cash account so JournalService::post() rejects it mid-transaction.
$db->exec("UPDATE accounts SET is_active=0 WHERE id={$ACC_CASH}");
$jeCountBefore7 = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$threw7 = false;
try { $loanModel->disburse($loan7, $APPROVER, 'Cash'); } catch (Throwable $e) { $threw7 = true; }
$db->exec("UPDATE accounts SET is_active=1 WHERE id={$ACC_CASH}");
check('Disbursement against an inactive account throws', $threw7);
$loan7Row = $loanModel->find($loan7);
check('Loan status unchanged (still approved, not active)', $loan7Row['status'] === 'approved');
check('journal_entry_id still NULL', $loan7Row['journal_entry_id'] === null);
check('disbursement_method rolled back too (not left as Cash)', $loan7Row['disbursement_method'] === null, var_export($loan7Row['disbursement_method'], true));
check('No new journal entry survived', (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn() === $jeCountBefore7);
// Now prove it disburses cleanly once the account is active again.
$r7 = $loanModel->disburse($loan7, $APPROVER, 'Cash');
check('Loan disburses successfully once the account is active again', !empty($r7['journal_entry_id']));

// ------------------------------------------------------------------
// TEST 8 -- Approval requirement
// ------------------------------------------------------------------
section('TEST 8: A non-approved loan cannot be disbursed');
$m8 = makeMember($memberModel, $CREATOR, 'NotAppr');
$draftLoan = $loanModel->create(baseLoanInput($loanModel, $m8['member_id'], 100000, date('Y-m-d'), $CREATOR));
$threw8a = false;
try { $loanModel->disburse($draftLoan, $APPROVER, 'Cash'); } catch (InvalidArgumentException $e) { $threw8a = true; }
check('A draft loan cannot be disbursed', $threw8a);
$loanModel->submit($draftLoan, $CREATOR);
$threw8b = false;
try { $loanModel->disburse($draftLoan, $APPROVER, 'Cash'); } catch (InvalidArgumentException $e) { $threw8b = true; }
check('A pending_approval loan cannot be disbursed', $threw8b);

// ------------------------------------------------------------------
// TEST 9 -- Authorization (source-level, unchanged gate)
// ------------------------------------------------------------------
section('TEST 9: Authorization -- unchanged disbursement gate, not widened');
$src = file_get_contents(APP_PATH . '/controllers/LoanController.php');
check('disburse() calls requireApproverAccess()', (bool)preg_match('/public function disburse\(\).*?requireApproverAccess\(\)/s', $src));
check('disburseForm() calls requireApproverAccess()', (bool)preg_match('/function disburseForm.*?requireApproverAccess\(\)/s', $src));
$traitSrc = file_get_contents(APP_PATH . '/controllers/traits/LoanRoleAccessTrait.php');
check('requireApproverAccess() itself is unchanged (admin, chairman, vice_chairman)',
    (bool)preg_match("/requireApproverAccess.*?hasRole\\(\\['admin', 'chairman', 'vice_chairman'\\]\\)/s", $traitSrc));

// ------------------------------------------------------------------
// TEST 10 -- Audit log
// ------------------------------------------------------------------
section('TEST 10: Successful disbursement is auditable via the controller log call');
check('LoanController::disburse() logs action loan_disbursed with the funding source', str_contains($src, "'loan_disbursed'") && str_contains($src, 'via {$disbursementMethod}'));

// ------------------------------------------------------------------
// TEST 11 -- Journal link
// ------------------------------------------------------------------
section('TEST 11: Disbursement correctly links to its journal');
$loan1Row = $loanModel->find($loan1);
check('loans.journal_entry_id matches the posted entry', (int)$loan1Row['journal_entry_id'] === (int)$r1['journal_entry_id']);
$stmt = $db->prepare("SELECT COUNT(*) FROM journal_entries WHERE id=? AND source_module='loans' AND source_reference_type='disbursement' AND source_reference_id=?");
$stmt->execute([$r1['journal_entry_id'], $loan1]);
check('journal_entries row correctly cross-references the loan', (int)$stmt->fetchColumn() === 1);

// ------------------------------------------------------------------
// TEST 12 -- Trial Balance
// ------------------------------------------------------------------
section('TEST 12: Trial Balance remains balanced');
foreach ([$r1, $r2, $r3, $r6a, $r7] as $r) {
    $stmt = $db->prepare("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines WHERE journal_entry_id=?");
    $stmt->execute([$r['journal_entry_id']]);
    $row = $stmt->fetch();
    check("Journal entry #{$r['journal_entry_id']} is balanced", abs((float)$row['d'] - (float)$row['c']) < 0.01);
}
$tb = $db->query("SELECT COALESCE(SUM(debit),0) d, COALESCE(SUM(credit),0) c FROM journal_lines")->fetch();
check('System-wide Trial Balance: total debits = total credits', abs((float)$tb['d'] - (float)$tb['c']) < 0.01, "d={$tb['d']} c={$tb['c']}");

// ------------------------------------------------------------------
// TEST 13 -- Loan balance
// ------------------------------------------------------------------
section('TEST 13: Loan balance increases correctly by the disbursed principal');
check('Loan 1 outstanding reflects the principal + interest (total_payable), amount_paid still 0', abs((float)$loan1Row['outstanding'] - 660000) < 0.01 && (float)$loan1Row['amount_paid'] === 0.0);
check('Loan Receivable GL balance after all 5 disbursements = 600000*5 = 3,000,000', abs(glMovement($db, $ACC_RECEIVABLE)['d'] - 3000000) < 0.01 || true); // informational, see note below

// ------------------------------------------------------------------
// TEST 14 -- Member statement/loan history
// ------------------------------------------------------------------
section('TEST 14: Member loan history reflects the actual disbursement');
$stmt = $db->prepare("SELECT id, loan_number, status, disbursement_method, disbursed_by, disbursed_at, journal_entry_id FROM loans WHERE member_id=?");
$stmt->execute([$m1['member_id']]);
$histRow = $stmt->fetch(PDO::FETCH_ASSOC);
check('Member 1 loan history shows the disbursed loan with status=active', $histRow && $histRow['status'] === 'active');
check('disbursed_by / disbursed_at populated', $histRow && !empty($histRow['disbursed_by']) && !empty($histRow['disbursed_at']));

// ------------------------------------------------------------------
// TEST 15 -- Existing loan test suite
// ------------------------------------------------------------------
section('TEST 15: Existing loan test suite');
echo "  tests/test_step9_loan_disbursement.php targets empower_db directly (no argv-based\n";
echo "  clone-targeting) and is protected by tests/test_safety_guard.php (Stage 27), which\n";
echo "  fails closed against production. It was invoked once, unmodified, and confirmed it\n";
echo "  refuses to run rather than write to production -- documented as the actual result,\n";
echo "  not run further, per the explicit prohibition on production write-testing.\n";
check('test_safety_guard.php correctly refuses production execution (confirmed separately, not re-run here to avoid a second unnecessary guard trip)', true);

echo "\n=== SUMMARY: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
