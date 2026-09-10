<?php
/**
 * Investments Module — comprehensive test suite.
 * Covers: schema/FKs, seed data, routes, permissions, maker-checker
 * workflow, posting, idempotency, income/withdrawal/disposal (at par,
 * gain, loss), transaction validation, immutability, and regression
 * of every prior test suite in this engagement.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$db = Database::getInstance()->getConnection();

echo "=== INVESTMENTS MODULE TEST SUITE ===\n\n";

$testsPassed = 0;
$testsFailed = 0;
function check($label, $cond) {
    global $testsPassed, $testsFailed;
    if ($cond) { echo "  PASS: $label\n"; $testsPassed++; }
    else       { echo "  FAIL: $label\n"; $testsFailed++; }
}

// ============================================================
// Baseline / before state (for the final forensic before/after check)
// ============================================================
$beforeAccounts   = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$beforeEntries    = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$beforeLines      = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$beforeTotals     = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$beforeLoans      = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
$beforeRepayments = (int)$db->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn();
$beforeSavings    = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$beforeExpenses   = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();
$beforeJESeq      = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='JE'")->fetchColumn();
$beforeINVSeq     = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='INV'")->fetchColumn();
$beforeINVTXSeq   = $db->query("SELECT last_number FROM journal_number_sequences WHERE prefix='INVTX'")->fetchColumn();

echo "Baseline: accounts={$beforeAccounts} entries={$beforeEntries} lines={$beforeLines} debit={$beforeTotals['d']} credit={$beforeTotals['c']}\n\n";

$createdInvestmentIds = [];
$createdTxnIds = [];
$createdTypeId = null;
$tempApproverId = null;

// Test accounts
$ACC_CASH = 7;      // 1110 Cash at Hand (asset)
$ACC_BANK = 10;      // 1140 Bank Accounts (asset)
$FIXED_DEPOSIT_TYPE_ID = (int)$db->query("SELECT id FROM investment_types WHERE type_name='Fixed Deposit'")->fetchColumn();
$SHARES_TYPE_ID = (int)$db->query("SELECT id FROM investment_types WHERE type_name='Shares'")->fetchColumn();

// ============================================================
// SECTION 1: SCHEMA / FK / SEED DATA
// ============================================================
echo "SECTION 1: Schema, foreign keys, seed data\n";
foreach (['investment_types', 'investments', 'investment_transactions'] as $t) {
    check("table $t exists", $db->query("SHOW TABLES LIKE '$t'")->fetch() !== false);
}
$loss = $db->query("SELECT code,name,type FROM accounts WHERE code='5310'")->fetch(PDO::FETCH_ASSOC);
check('account 5310 Loss on Investment Disposal exists', $loss && $loss['name'] === 'Loss on Investment Disposal' && $loss['type'] === 'expense');
check('5 investment_types seeded', (int)$db->query('SELECT COUNT(*) FROM investment_types')->fetchColumn() === 5);
$types = $db->query("SELECT it.type_name, aa.code ac, ai.code ic, al.code lc FROM investment_types it
    JOIN accounts aa ON aa.id=it.asset_gl_account_id JOIN accounts ai ON ai.id=it.income_gl_account_id
    LEFT JOIN accounts al ON al.id=it.loss_gl_account_id")->fetchAll(PDO::FETCH_ASSOC);
$allCorrect = true;
foreach ($types as $t) {
    if ($t['ac'] !== '1040') $allCorrect = false;
    if ($t['type_name'] === 'Property' && $t['ic'] !== '4160') $allCorrect = false;
    if ($t['type_name'] !== 'Property' && $t['ic'] !== '4030') $allCorrect = false;
    if ($t['lc'] !== '5310') $allCorrect = false;
}
check('all investment_types map to correct accounts (1040 asset, 4030/4160 income, 5310 loss)', $allCorrect);
check("journal_number_sequences has INV row", $db->query("SELECT prefix FROM journal_number_sequences WHERE prefix='INV'")->fetch() !== false);
check("journal_number_sequences has INVTX row", $db->query("SELECT prefix FROM journal_number_sequences WHERE prefix='INVTX'")->fetch() !== false);
echo "\n";

// ============================================================
// SECTION 2: ROUTES
// ============================================================
echo "SECTION 2: Route resolution\n";
$routesFile = file_get_contents('index.php');
foreach ([
    'investments', 'investment-create', 'investment-store', 'investment-view',
    'investment-submit', 'investment-approve', 'investment-reject', 'investment-post',
    'investment-transaction-create', 'investment-transaction-store',
    'investment-types', 'investment-type-store', 'investment-type-toggle',
] as $route) {
    check("route '$route' registered", (bool)preg_match("/'" . preg_quote($route, '/') . "'\s*=>/", $routesFile));
}
check("'investments' route points to InvestmentController", (bool)preg_match("/'investments'\s*=>\s*\['InvestmentController'/", $routesFile));
echo "\n";

// ============================================================
// SECTION 3: MODEL/CONTROLLER LOADING
// ============================================================
echo "SECTION 3: Model/controller class loading\n";
check('InvestmentTypeModel class exists', class_exists('InvestmentTypeModel'));
check('InvestmentModel class exists', class_exists('InvestmentModel'));
check('InvestmentTransactionModel class exists', class_exists('InvestmentTransactionModel'));
check('InvestmentController class exists', class_exists('InvestmentController'));
$typeModel = new InvestmentTypeModel();
$model = new InvestmentModel();
$txnModel = new InvestmentTransactionModel();
check('models instantiate without error', true);
echo "\n";

// ============================================================
// Create a temporary second user for maker-checker tests.
// Defensively remove any leftover row from a previously-crashed run
// first, so this script is safe to re-run without manual cleanup.
// ============================================================
$db->prepare("DELETE FROM users WHERE email = 'test.checker.investments@empower.local'")->execute();
$db->prepare("INSERT INTO users (role_id, full_name, email, password_hash, is_active) VALUES (1, 'TEST Investment Checker', 'test.checker.investments@empower.local', 'x', 1)")->execute();
$tempApproverId = (int)$db->lastInsertId();
echo "Created temporary checker user id={$tempApproverId} (deleted during cleanup)\n\n";

// ============================================================
// SECTION 4: PERMISSIONS (via CLI harness, matching Task 6.5's pattern)
// ============================================================
echo "SECTION 4: Permissions\n";
$permHelper = __DIR__ . '/test_investments_permissions_helper.php';
file_put_contents($permHelper, <<<'PHP'
<?php
require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Autoloader.php';
require 'core/Session.php';
Session::start();
Session::set('last_activity', time());
$scenario = $argv[1];
if ($scenario === 'unauthenticated_index') {
    $c = new InvestmentController();
    $c->index();
    echo "REACHED_AFTER_INDEX";
} elseif ($scenario === 'viewer_create') {
    Session::set('user_id', 1);
    Session::set('user_role', 'viewer');
    $c = new InvestmentController();
    $c->create();
} elseif ($scenario === 'viewer_approve') {
    Session::set('user_id', 1);
    Session::set('user_role', 'viewer');
    $c = new InvestmentController();
    $_POST['investment_id'] = 1;
    $c->approve();
} elseif ($scenario === 'treasurer_approve') {
    Session::set('user_id', 1);
    Session::set('user_role', 'treasurer');
    $c = new InvestmentController();
    $_POST['investment_id'] = 1;
    $c->approve();
}
PHP);
$out1 = shell_exec('"' . PHP_BINARY . '" "' . $permHelper . '" unauthenticated_index 2>&1');
check('unauthenticated request never reaches past requireAuth()', !str_contains($out1 ?? '', 'REACHED_AFTER_INDEX'));
$out2 = shell_exec('"' . PHP_BINARY . '" "' . $permHelper . '" viewer_create 2>&1');
check('viewer blocked from create()', str_contains($out2 ?? '', 'Access denied'));
$out3 = shell_exec('"' . PHP_BINARY . '" "' . $permHelper . '" viewer_approve 2>&1');
check('viewer blocked from approve()', str_contains($out3 ?? '', 'Access denied'));
$out4 = shell_exec('"' . PHP_BINARY . '" "' . $permHelper . '" treasurer_approve 2>&1');
check('treasurer (non-admin) blocked from approve() -- checker is admin-only', str_contains($out4 ?? '', 'Access denied'));
unlink($permHelper);
echo "\n";

// ============================================================
// SECTION 5: INVESTMENT CREATION + MAKER-CHECKER WORKFLOW
// ============================================================
echo "SECTION 5: Investment creation and maker-checker workflow\n";
$inv1 = null;
try {
    $inv1 = $model->createDraft([
        'investment_type_id' => $FIXED_DEPOSIT_TYPE_ID,
        'provider_name'      => 'TEST Bank',
        'reference'          => 'FD-TEST-001',
        'principal_amount'   => 10000000.00,
        'start_date'         => '2026-08-01',
        'maturity_date'      => '2027-02-01',
        'expected_rate'      => 12.5,
        'funding_account_id' => $ACC_BANK,
        'notes'              => 'INVTEST fixed deposit',
    ], 1);
    $createdInvestmentIds[] = $inv1;
    $row = $model->find($inv1);
    check('investment created as draft', $row['status'] === 'draft');
    check('investment_number assigned (INV-xxxxxx)', (bool)preg_match('/^INV-\d{6}$/', $row['investment_number']));
    check('investment_account_id defaulted from type (1040)', (int)$row['investment_account_id'] === 4);
} catch (Exception $e) {
    check('investment creation threw unexpectedly: ' . $e->getMessage(), false);
}

try {
    $model->createDraft(['investment_type_id' => $FIXED_DEPOSIT_TYPE_ID, 'principal_amount' => 0, 'start_date' => '2026-08-01', 'funding_account_id' => $ACC_BANK], 1);
    check('zero principal amount should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('zero principal amount blocked — ' . $e->getMessage(), true);
}

try {
    $model->submit($inv1, 1);
    $row = $model->find($inv1);
    check('submit -> pending_approval', $row['status'] === 'pending_approval');
} catch (Exception $e) {
    check('submit threw unexpectedly: ' . $e->getMessage(), false);
}

try {
    $model->approve($inv1, 1);
    check('self-approval should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('self-approval blocked — ' . $e->getMessage(), true);
}

try {
    $model->approve($inv1, $tempApproverId);
    $row = $model->find($inv1);
    check('different-user approve -> approved', $row['status'] === 'approved');
    check('approved_by is the checker, not the preparer', (int)$row['approved_by'] === $tempApproverId);
} catch (Exception $e) {
    check('approve threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 6: REJECTION PATH (separate investment)
// ============================================================
echo "SECTION 6: Rejection requires a reason, then resubmit\n";
$inv2 = null;
try {
    $inv2 = $model->createDraft([
        'investment_type_id' => $SHARES_TYPE_ID,
        'principal_amount'   => 2000000.00,
        'start_date'         => '2026-08-05',
        'funding_account_id' => $ACC_CASH,
    ], 1);
    $createdInvestmentIds[] = $inv2;
    $model->submit($inv2, 1);

    try {
        $model->reject($inv2, $tempApproverId, '');
        check('empty-reason rejection should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('empty-reason rejection blocked', true);
    }

    $model->reject($inv2, $tempApproverId, 'Needs more documentation');
    $row = $model->find($inv2);
    check('status is rejected', $row['status'] === 'rejected');
    check('rejection_reason stored', $row['rejection_reason'] === 'Needs more documentation');

    $model->submit($inv2, 1); // resubmit from rejected
    $row = $model->find($inv2);
    check('rejected investment can be resubmitted', $row['status'] === 'pending_approval');
    $model->approve($inv2, $tempApproverId);
} catch (Exception $e) {
    check('SECTION 6 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 7: POSTING + JOURNAL ENTRY + IDEMPOTENCY
// ============================================================
echo "SECTION 7: Posting, journal entry correctness, idempotency\n";
try {
    $entriesBefore = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $result1 = $model->post($inv1, 1);
    check('post() returns created=true first time', $result1['created'] === true);
    $entriesAfter = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('exactly one new journal entry created', $entriesAfter === $entriesBefore + 1);

    $lines = $db->prepare("SELECT jl.*, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id = ? ORDER BY jl.id");
    $lines->execute([$result1['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('exactly 2 lines for placement', count($lines) === 2);
    check('Dr line is investment asset account 1040 for 10,000,000.00', $lines[0]['code'] === '1040' && abs((float)$lines[0]['debit'] - 10000000.00) < 0.01 && (float)$lines[0]['credit'] === 0.0);
    check('Cr line is funding account 1140 for 10,000,000.00', $lines[1]['code'] === '1140' && abs((float)$lines[1]['credit'] - 10000000.00) < 0.01 && (float)$lines[1]['debit'] === 0.0);

    $je = $db->prepare("SELECT * FROM journal_entries WHERE id=?");
    $je->execute([$result1['journal_entry_id']]);
    $je = $je->fetch(PDO::FETCH_ASSOC);
    check('source_module=investments', $je['source_module'] === 'investments');
    check('source_reference_type=investment_placement', $je['source_reference_type'] === 'investment_placement');
    check('source_reference_id=investment id', (int)$je['source_reference_id'] === $inv1);
    check('financial_year_id resolved (not null)', $je['financial_year_id'] !== null);
    check('accounting_period_id resolved (not null)', $je['accounting_period_id'] !== null);

    $row = $model->find($inv1);
    check('investment status is posted', $row['status'] === 'posted');
    check('journal_entry_id stored on investment', (int)$row['journal_entry_id'] === (int)$result1['journal_entry_id']);

    // Idempotency
    $entriesBefore2 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $result2 = $model->post($inv1, 1);
    $entriesAfter2 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('re-posting an already-posted investment does not create a new entry', $entriesAfter2 === $entriesBefore2);
    check('re-post returns created=false', $result2['created'] === false);
    check('re-post returns same journal_entry_id', (int)$result2['journal_entry_id'] === (int)$result1['journal_entry_id']);

    // Post the second investment too (needed for later transaction tests)
    $model->post($inv2, 1);
} catch (Exception $e) {
    check('SECTION 7 threw unexpectedly: ' . $e->getMessage(), false);
}

try {
    $draftInv = $model->createDraft(['investment_type_id' => $FIXED_DEPOSIT_TYPE_ID, 'principal_amount' => 500000, 'start_date' => '2026-08-01', 'funding_account_id' => $ACC_CASH], 1);
    $createdInvestmentIds[] = $draftInv;
    $model->post($draftInv, 1);
    check('posting a non-approved (draft) investment should have thrown', false);
} catch (InvalidArgumentException $e) {
    check('posting a draft investment blocked — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// SECTION 8: TRANSACTION VALIDATION (pre-posting guards)
// ============================================================
echo "SECTION 8: Transaction validation before an investment is posted\n";
$inv3 = null;
try {
    $inv3 = $model->createDraft(['investment_type_id' => $FIXED_DEPOSIT_TYPE_ID, 'principal_amount' => 1000000, 'start_date' => '2026-08-10', 'funding_account_id' => $ACC_CASH], 1);
    $createdInvestmentIds[] = $inv3;

    $txnId = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'income', 'amount' => 50000, 'transaction_date' => '2026-08-15', 'funding_account_id' => $ACC_CASH], 1);
    $createdTxnIds[] = $txnId;
    try {
        $txnModel->post($txnId, 1);
        check('income against a draft investment should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('income against a draft (not yet posted) investment blocked — ' . $e->getMessage(), true);
    }

    // amount <= 0
    try {
        $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'income', 'amount' => 0, 'transaction_date' => '2026-08-15', 'funding_account_id' => $ACC_CASH], 1);
        check('zero-amount transaction should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('zero-amount transaction blocked', true);
    }

    // date before start_date
    try {
        $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'income', 'amount' => 1000, 'transaction_date' => '2026-08-01', 'funding_account_id' => $ACC_CASH], 1);
        check('transaction dated before investment start_date should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('transaction dated before start_date blocked', true);
    }
} catch (Exception $e) {
    check('SECTION 8 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 9: INVESTMENT INCOME
// ============================================================
echo "SECTION 9: Investment income transaction\n";
try {
    $model->submit($inv3, 1);
    $model->approve($inv3, $tempApproverId);
    $model->post($inv3, 1);

    $incomeTxn = $txnModel->createDraft([
        'investment_id' => $inv3, 'transaction_type' => 'income', 'amount' => 45000.00,
        'transaction_date' => '2026-08-20', 'funding_account_id' => $ACC_CASH, 'description' => 'INVTEST interest payment',
    ], 1);
    $createdTxnIds[] = $incomeTxn;
    $entriesBefore = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $result = $txnModel->post($incomeTxn, 1);
    $entriesAfter = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('income posting creates one new journal entry', $entriesAfter === $entriesBefore + 1);

    $lines = $db->prepare("SELECT jl.*, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id=? ORDER BY jl.id");
    $lines->execute([$result['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('income: Dr Cash (1110) 45,000.00', $lines[0]['code'] === '1110' && abs((float)$lines[0]['debit'] - 45000.00) < 0.01);
    check('income: Cr Investment Income (4030) 45,000.00', $lines[1]['code'] === '4030' && abs((float)$lines[1]['credit'] - 45000.00) < 0.01);

    check('investment income_received reflects the posted income', abs($model->incomeReceived($inv3) - 45000.00) < 0.01);
    check('carrying amount unchanged by income', abs($model->carryingAmount($inv3, 1000000.00) - 1000000.00) < 0.01);

    // idempotency on the transaction row
    $entriesBefore2 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    $result2 = $txnModel->post($incomeTxn, 1);
    $entriesAfter2 = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
    check('re-posting an already-posted transaction does not create a new entry', $entriesAfter2 === $entriesBefore2);
    check('re-post returns same journal_entry_id', (int)$result2['journal_entry_id'] === (int)$result['journal_entry_id']);
} catch (Exception $e) {
    check('SECTION 9 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 10: PARTIAL WITHDRAWAL
// ============================================================
echo "SECTION 10: Partial withdrawal\n";
try {
    // withdrawal exceeding carrying amount
    $bigWithdrawal = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'withdrawal', 'amount' => 2000000, 'transaction_date' => '2026-08-21', 'funding_account_id' => $ACC_CASH], 1);
    $createdTxnIds[] = $bigWithdrawal;
    try {
        $txnModel->post($bigWithdrawal, 1);
        check('withdrawal exceeding carrying amount should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('withdrawal exceeding carrying amount blocked — ' . $e->getMessage(), true);
    }

    $withdrawal = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'withdrawal', 'amount' => 300000.00, 'transaction_date' => '2026-08-22', 'funding_account_id' => $ACC_CASH], 1);
    $createdTxnIds[] = $withdrawal;
    $result = $txnModel->post($withdrawal, 1);

    $lines = $db->prepare("SELECT jl.*, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id=? ORDER BY jl.id");
    $lines->execute([$result['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('withdrawal: Dr Cash 300,000.00', $lines[0]['code'] === '1110' && abs((float)$lines[0]['debit'] - 300000.00) < 0.01);
    check('withdrawal: Cr Investments (1040) 300,000.00', $lines[1]['code'] === '1040' && abs((float)$lines[1]['credit'] - 300000.00) < 0.01);

    $carrying = $model->carryingAmount($inv3, 1000000.00);
    check('carrying amount reduced to 700,000.00', abs($carrying - 700000.00) < 0.01);
} catch (Exception $e) {
    check('SECTION 10 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 11: DISPOSAL AT CARRYING AMOUNT
// ============================================================
echo "SECTION 11: Disposal at carrying amount (700,000 remaining)\n";
try {
    $disposal = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'disposal', 'amount' => 700000.00, 'transaction_date' => '2026-08-25', 'funding_account_id' => $ACC_CASH], 1);
    $createdTxnIds[] = $disposal;
    $result = $txnModel->post($disposal, 1);

    $lines = $db->prepare("SELECT jl.*, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id=? ORDER BY jl.id");
    $lines->execute([$result['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('disposal-at-par: exactly 2 lines', count($lines) === 2);
    check('disposal-at-par: Dr Cash 700,000.00', $lines[0]['code'] === '1110' && abs((float)$lines[0]['debit'] - 700000.00) < 0.01);
    check('disposal-at-par: Cr Investments 700,000.00', $lines[1]['code'] === '1040' && abs((float)$lines[1]['credit'] - 700000.00) < 0.01);

    $row = $model->find($inv3);
    check('investment status is disposed', $row['status'] === 'disposed');
    check('carrying amount is now 0', abs($model->carryingAmount($inv3, 1000000.00)) < 0.01);

    // no transactions after full disposal
    try {
        $extra = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'income', 'amount' => 1000, 'transaction_date' => '2026-08-26', 'funding_account_id' => $ACC_CASH], 1);
        $createdTxnIds[] = $extra;
        $txnModel->post($extra, 1);
        check('transaction after full disposal should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('transaction after full disposal blocked — ' . $e->getMessage(), true);
    }

    // second disposal blocked
    try {
        $dup = $txnModel->createDraft(['investment_id' => $inv3, 'transaction_type' => 'disposal', 'amount' => 100, 'transaction_date' => '2026-08-26', 'funding_account_id' => $ACC_CASH], 1);
        $createdTxnIds[] = $dup;
        $txnModel->post($dup, 1);
        check('second disposal should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('second disposal on same investment blocked (also caught by status=disposed guard)', true);
    }
} catch (Exception $e) {
    check('SECTION 11 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 12: DISPOSAL WITH GAIN
// ============================================================
echo "SECTION 12: Disposal above carrying amount (gain)\n";
$inv4 = null;
try {
    $inv4 = $model->createDraft(['investment_type_id' => $SHARES_TYPE_ID, 'principal_amount' => 10000000.00, 'start_date' => '2026-08-01', 'funding_account_id' => $ACC_BANK], 1);
    $createdInvestmentIds[] = $inv4;
    $model->submit($inv4, 1);
    $model->approve($inv4, $tempApproverId);
    $model->post($inv4, 1);

    $disposal = $txnModel->createDraft(['investment_id' => $inv4, 'transaction_type' => 'disposal', 'amount' => 11000000.00, 'transaction_date' => '2026-08-20', 'funding_account_id' => $ACC_BANK], 1);
    $createdTxnIds[] = $disposal;
    $result = $txnModel->post($disposal, 1);

    $lines = $db->prepare("SELECT jl.*, a.code, a.name FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id=? ORDER BY jl.id");
    $lines->execute([$result['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('gain disposal: exactly 3 lines', count($lines) === 3);
    check('gain disposal: Dr Bank (1140) 11,000,000.00 (full proceeds)', $lines[0]['code'] === '1140' && abs((float)$lines[0]['debit'] - 11000000.00) < 0.01);
    check('gain disposal: Cr Investments (1040) 10,000,000.00 (carrying only, never more)', $lines[1]['code'] === '1040' && abs((float)$lines[1]['credit'] - 10000000.00) < 0.01);
    check('gain disposal: Cr Investment Income (4030) 1,000,000.00 (the gain)', $lines[2]['code'] === '4030' && abs((float)$lines[2]['credit'] - 1000000.00) < 0.01);

    $totalDebit = array_sum(array_column($lines, 'debit'));
    $totalCredit = array_sum(array_column($lines, 'credit'));
    check('gain disposal entry is balanced', abs($totalDebit - $totalCredit) < 0.01);

    $je = $db->prepare("SELECT description FROM journal_entries WHERE id=?");
    $je->execute([$result['journal_entry_id']]);
    $desc = $je->fetchColumn();
    check('journal entry description identifies this as a disposal', str_contains($desc, 'disposal') || str_contains($desc, 'Disposal'));
} catch (Exception $e) {
    check('SECTION 12 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 13: DISPOSAL WITH LOSS
// ============================================================
echo "SECTION 13: Disposal below carrying amount (loss)\n";
$inv5 = null;
try {
    $inv5 = $model->createDraft(['investment_type_id' => $SHARES_TYPE_ID, 'principal_amount' => 5000000.00, 'start_date' => '2026-08-01', 'funding_account_id' => $ACC_BANK], 1);
    $createdInvestmentIds[] = $inv5;
    $model->submit($inv5, 1);
    $model->approve($inv5, $tempApproverId);
    $model->post($inv5, 1);

    $disposal = $txnModel->createDraft(['investment_id' => $inv5, 'transaction_type' => 'disposal', 'amount' => 4200000.00, 'transaction_date' => '2026-08-22', 'funding_account_id' => $ACC_BANK], 1);
    $createdTxnIds[] = $disposal;
    $result = $txnModel->post($disposal, 1);

    $lines = $db->prepare("SELECT jl.*, a.code FROM journal_lines jl JOIN accounts a ON a.id=jl.account_id WHERE journal_entry_id=? ORDER BY jl.id");
    $lines->execute([$result['journal_entry_id']]);
    $lines = $lines->fetchAll(PDO::FETCH_ASSOC);
    check('loss disposal: exactly 3 lines', count($lines) === 3);
    check('loss disposal: Dr Bank 4,200,000.00 (proceeds)', $lines[0]['code'] === '1140' && abs((float)$lines[0]['debit'] - 4200000.00) < 0.01);
    check('loss disposal: Dr Loss on Investment Disposal (5310) 800,000.00', $lines[1]['code'] === '5310' && abs((float)$lines[1]['debit'] - 800000.00) < 0.01);
    check('loss disposal: Cr Investments (1040) 5,000,000.00 (full carrying amount)', $lines[2]['code'] === '1040' && abs((float)$lines[2]['credit'] - 5000000.00) < 0.01);

    $totalDebit = array_sum(array_column($lines, 'debit'));
    $totalCredit = array_sum(array_column($lines, 'credit'));
    check('loss disposal entry is balanced', abs($totalDebit - $totalCredit) < 0.01);
} catch (Exception $e) {
    check('SECTION 13 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 14: LOSS WITHOUT A CONFIGURED LOSS ACCOUNT
// ============================================================
echo "SECTION 14: Loss disposal blocked cleanly when no loss account is configured\n";
$noLossTypeId = null;
try {
    $noLossTypeId = $typeModel->create([
        'type_name' => 'INVTEST No-Loss-Account Type',
        'asset_gl_account_id' => 4, // 1040
        'income_gl_account_id' => 31, // 4030
        'loss_gl_account_id' => null,
        'is_active' => 1,
    ]);
    $createdTypeId = $noLossTypeId;

    $inv6 = $model->createDraft(['investment_type_id' => $noLossTypeId, 'principal_amount' => 1000000.00, 'start_date' => '2026-08-01', 'funding_account_id' => $ACC_CASH], 1);
    $createdInvestmentIds[] = $inv6;
    $model->submit($inv6, 1);
    $model->approve($inv6, $tempApproverId);
    $model->post($inv6, 1);

    $disposal = $txnModel->createDraft(['investment_id' => $inv6, 'transaction_type' => 'disposal', 'amount' => 800000.00, 'transaction_date' => '2026-08-20', 'funding_account_id' => $ACC_CASH], 1);
    $createdTxnIds[] = $disposal;
    try {
        $txnModel->post($disposal, 1);
        check('loss disposal with no loss account configured should have thrown', false);
    } catch (InvalidArgumentException $e) {
        check('loss disposal blocked cleanly with a clear message — ' . $e->getMessage(), str_contains($e->getMessage(), 'Loss'));
    }
    // Investment must NOT have been advanced to disposed since the post failed.
    $row = $model->find($inv6);
    check('investment status still posted (not disposed) after the blocked loss', $row['status'] === 'posted');
} catch (Exception $e) {
    check('SECTION 14 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// SECTION 15: IMMUTABILITY
// ============================================================
echo "SECTION 15: Immutability after posting\n";
try {
    $model->update($inv1, ['principal_amount' => 999]);
    check('editing a posted investment should have thrown', false);
} catch (RuntimeException $e) {
    check('posted investment update blocked — ' . $e->getMessage(), true);
}
try {
    $model->delete($inv1);
    check('deleting a posted investment should have thrown', false);
} catch (RuntimeException $e) {
    check('posted investment delete blocked — ' . $e->getMessage(), true);
}
try {
    $txnModel->update($incomeTxn, ['amount' => 1]);
    check('editing a posted transaction should have thrown', false);
} catch (RuntimeException $e) {
    check('posted transaction update blocked — ' . $e->getMessage(), true);
}
echo "\n";

// ============================================================
// SECTION 16: ACCOUNTING REPORTS STILL CONSISTENT
// ============================================================
echo "SECTION 16: Accounting reports remain consistent\n";
try {
    $reportModel = new AccountingReportModel();
    $tb = $reportModel->trialBalance(['financial_year_id' => 2]);
    check('trial balance still balances after all investment postings', $tb['balanced']);

    $totals = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
    check('overall ledger debit == credit after all investment activity', abs((float)$totals['d'] - (float)$totals['c']) < 0.01);
} catch (Exception $e) {
    check('SECTION 16 threw unexpectedly: ' . $e->getMessage(), false);
}
echo "\n";

// ============================================================
// CLEANUP — remove every row this suite created, restore sequences
// ============================================================
echo "=== CLEANUP ===\n";
try {
    foreach ($createdTxnIds as $tid) {
        $t = $db->prepare("SELECT journal_entry_id FROM investment_transactions WHERE id=?");
        $t->execute([$tid]);
        $jeId = $t->fetchColumn();
        if ($jeId) {
            $db->prepare("DELETE FROM journal_lines WHERE journal_entry_id=?")->execute([$jeId]);
            $db->prepare("DELETE FROM journal_entry_audit WHERE entity_type='journal_entry' AND entity_id=?")->execute([$jeId]);
            $db->prepare("DELETE FROM journal_entries WHERE id=?")->execute([$jeId]);
        }
        $db->prepare("DELETE FROM investment_transactions WHERE id=?")->execute([$tid]);
    }
    foreach ($createdInvestmentIds as $iid) {
        $inv = $db->prepare("SELECT journal_entry_id FROM investments WHERE id=?");
        $inv->execute([$iid]);
        $jeId = $inv->fetchColumn();
        $db->prepare("DELETE FROM journal_entry_audit WHERE entity_type='investment' AND entity_id=?")->execute([$iid]);
        $db->prepare("DELETE FROM investment_transactions WHERE investment_id=?")->execute([$iid]);
        $db->prepare("DELETE FROM investments WHERE id=?")->execute([$iid]);
        if ($jeId) {
            $db->prepare("DELETE FROM journal_lines WHERE journal_entry_id=?")->execute([$jeId]);
            $db->prepare("DELETE FROM journal_entry_audit WHERE entity_type='journal_entry' AND entity_id=?")->execute([$jeId]);
            $db->prepare("DELETE FROM journal_entries WHERE id=?")->execute([$jeId]);
        }
    }
    if ($createdTypeId) {
        $db->prepare("DELETE FROM investment_types WHERE id=?")->execute([$createdTypeId]);
    }
    if ($tempApproverId) {
        $db->prepare("DELETE FROM users WHERE id=?")->execute([$tempApproverId]);
    }
    $db->prepare("UPDATE journal_number_sequences SET last_number=? WHERE prefix='JE'")->execute([$beforeJESeq]);
    $db->prepare("UPDATE journal_number_sequences SET last_number=? WHERE prefix='INV'")->execute([$beforeINVSeq]);
    $db->prepare("UPDATE journal_number_sequences SET last_number=? WHERE prefix='INVTX'")->execute([$beforeINVTXSeq]);
    echo "Cleanup complete: removed " . count($createdInvestmentIds) . " investments, " . count($createdTxnIds) . " transactions, sequences restored.\n\n";
} catch (Exception $e) {
    echo "Cleanup error: " . $e->getMessage() . "\n\n";
}

// ============================================================
// FORENSIC BEFORE/AFTER CHECK (per Section 14 of the task)
// ============================================================
echo "=== FORENSIC BEFORE/AFTER CHECK ===\n";
$afterAccounts   = (int)$db->query('SELECT COUNT(*) FROM accounts')->fetchColumn();
$afterEntries    = (int)$db->query('SELECT COUNT(*) FROM journal_entries')->fetchColumn();
$afterLines      = (int)$db->query('SELECT COUNT(*) FROM journal_lines')->fetchColumn();
$afterTotals     = $db->query('SELECT SUM(debit) d, SUM(credit) c FROM journal_lines')->fetch();
$afterLoans      = (int)$db->query('SELECT COUNT(*) FROM loans')->fetchColumn();
$afterRepayments = (int)$db->query('SELECT COUNT(*) FROM loan_repayments')->fetchColumn();
$afterSavings    = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
$afterExpenses   = (int)$db->query('SELECT COUNT(*) FROM expenses')->fetchColumn();

check('accounts count unchanged from baseline (this suite adds no new accounts of its own beyond the migration, already reflected in baseline)', $afterAccounts === $beforeAccounts);
check('journal_entries count restored to baseline after cleanup', $afterEntries === $beforeEntries);
check('journal_lines count restored to baseline after cleanup', $afterLines === $beforeLines);
check('debit total restored to baseline', abs((float)$afterTotals['d'] - (float)$beforeTotals['d']) < 0.01);
check('credit total restored to baseline', abs((float)$afterTotals['c'] - (float)$beforeTotals['c']) < 0.01);
check('loans count unchanged', $afterLoans === $beforeLoans);
check('loan_repayments count unchanged', $afterRepayments === $beforeRepayments);
check('savings count unchanged', $afterSavings === $beforeSavings);
check('expenses count unchanged', $afterExpenses === $beforeExpenses);
check('investments table empty after cleanup', (int)$db->query('SELECT COUNT(*) FROM investments')->fetchColumn() === 0);
check('investment_transactions table empty after cleanup', (int)$db->query('SELECT COUNT(*) FROM investment_transactions')->fetchColumn() === 0);
echo "\n";

// ============================================================
// REGRESSION — all prior test suites in this engagement
// ============================================================
echo "=== REGRESSION: prior test suites ===\n";
$suites = [
    'test_journal_reversal.php' => 'ALL REVERSAL TESTS PASSED',
    'test_step4_periods.php' => 'ALL STEP 4 TESTS PASSED',
    'test_step5_opening_balances.php' => 'ALL STEP 5 TESTS PASSED',
    'test_step6_accounting_reports.php' => 'ALL STEP 6 TESTS PASSED',
    'test_step7_expenses.php' => 'ALL STEP 7 TESTS PASSED',
    'test_step8_savings_accounting.php' => 'ALL STEP 8 TESTS PASSED',
    'test_step9_loan_disbursement.php' => 'ALL STEP 9 TESTS PASSED',
    'test_task65_chart_of_accounts.php' => 'ALL TASK 6.5 TESTS PASSED',
];
foreach ($suites as $file => $marker) {
    $out = shell_exec('"' . PHP_BINARY . '" ' . $file . ' 2>&1');
    $passed = str_contains($out ?? '', $marker);
    check("{$file} reports all tests passed", $passed);
    if (!$passed) { echo substr($out ?? '', -1500) . "\n"; }
}
echo "\n";

// ============================================================
// SUMMARY
// ============================================================
echo "=== TEST SUMMARY ===\n";
echo "Tests Passed: {$testsPassed}\n";
echo "Tests Failed: {$testsFailed}\n\n";

if ($testsFailed === 0) {
    echo "ALL INVESTMENTS MODULE TESTS PASSED\n";
    exit(0);
} else {
    echo "SOME TESTS FAILED\n";
    exit(1);
}
