<?php
/**
 * ISOLATED — one-off functional audit of the Internal Voucher maker-checker
 * workflow, run against empower_db_voucher_test only. Not a permanent
 * regression suite -- ad hoc verification for a user-requested audit.
 */
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'empower_db_voucher_test');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_PATH', __DIR__ . '/app');
define('CORE_PATH', __DIR__ . '/core');
require_once CORE_PATH . '/Database.php';
require_once CORE_PATH . '/Model.php';
require_once CORE_PATH . '/Autoloader.php';

$db = Database::getInstance()->getConnection();
$pass = 0; $fail = 0;
function ok(bool $c, string $l, string $d = ''): void { global $pass, $fail; if ($c) { $pass++; echo "  [PASS] $l\n"; } else { $fail++; echo "  [FAIL] $l -- $d\n"; } }

$model = new InternalVoucherModel();
$preparerId = 1;
$approverId = 294;

echo "=== 1. Create draft (debit voucher, expense category path) ===\n";
$catId = (int)$db->query("SELECT id FROM expense_categories LIMIT 1")->fetchColumn();
if (!$catId) {
    $db->exec("INSERT INTO expense_categories (category_name, gl_account_id, is_active) VALUES ('Test Cat', 72, 1)");
    $catId = (int)$db->lastInsertId();
}
$id = $model->createDraft([
    'voucher_type' => 'debit', 'voucher_date' => '2026-08-15',
    'expense_category_id' => $catId, 'contra_account_id' => 7,
    'narration' => 'Test internal voucher — office supplies reimbursement', 'amount' => 25000,
], $preparerId);
$v = $model->find($id);
ok($v['status'] === 'draft', 'Voucher created with status=draft');
ok($v['voucher_number'] === 'IV-000001', 'Voucher number auto-generated as IV-000001', $v['voucher_number']);
ok((int)$v['recorded_by'] === $preparerId, 'recorded_by (Prepared By) correctly set to the preparer');
ok($v['narration'] === 'Test internal voucher — office supplies reimbursement', 'Being (narration) stored correctly');

echo "\n=== 2. Submit for approval ===\n";
$model->submit($id, $preparerId);
$v = $model->find($id);
ok($v['status'] === 'pending_approval', 'Status transitions to pending_approval');
ok($v['submitted_at'] !== null, 'submitted_at timestamp recorded');

echo "\n=== 3. Preparer attempts to approve their own voucher -- must be blocked ===\n";
$threw = false; $msg = '';
try { $model->approve($id, $preparerId); } catch (InvalidArgumentException $e) { $threw = true; $msg = $e->getMessage(); }
ok($threw, 'Self-approval correctly rejected', $msg);
$v = $model->find($id);
ok($v['status'] === 'pending_approval', 'Status unchanged after blocked self-approval attempt');

echo "\n=== 4. A different user (approver) approves ===\n";
$model->approve($id, $approverId);
$v = $model->find($id);
ok($v['status'] === 'approved', 'Status transitions to approved');
ok((int)$v['approved_by'] === $approverId, 'approved_by (Approved By) correctly set to the approver');
ok($v['approved_at'] !== null, 'approved_at timestamp recorded');

echo "\n=== 5. Post to ledger ===\n";
$result = $model->post($id, $preparerId);
$v = $model->find($id);
ok($v['status'] === 'posted', 'Status transitions to posted');
ok(!empty($v['journal_entry_id']), 'journal_entry_id linked');
$lines = $db->prepare("SELECT * FROM journal_lines WHERE journal_entry_id=?");
$lines->execute([$v['journal_entry_id']]);
$lines = $lines->fetchAll();
ok(count($lines) === 2, 'Journal has exactly 2 lines (Dr expense / Cr cash)');
$debitLine = null; $creditLine = null;
foreach ($lines as $l) { if ($l['debit'] > 0) $debitLine = $l; if ($l['credit'] > 0) $creditLine = $l; }
ok($debitLine && (int)$debitLine['account_id'] === 72, 'Debit line hits the expense category\'s mapped account (72)');
ok($creditLine && (int)$creditLine['account_id'] === 7, 'Credit line hits the contra account (Cash, 7)');
ok(abs((float)$debitLine['debit'] - 25000) < 0.01 && abs((float)$creditLine['credit'] - 25000) < 0.01, 'Amounts match (25,000 balanced)');

echo "\n=== 6. Idempotency: posting again returns the same journal, doesn't duplicate ===\n";
$beforeJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
$result2 = $model->post($id, $preparerId);
$afterJE = (int)$db->query("SELECT COUNT(*) FROM journal_entries")->fetchColumn();
ok($beforeJE === $afterJE, 'Re-posting an already-posted voucher creates no duplicate journal entry');
ok($result2['journal_entry_id'] === $result['journal_entry_id'], 'Same journal_entry_id returned');

echo "\n=== 7. Audit trail captured every step ===\n";
$audit = $model->auditTrail($id);
$actions = array_column($audit, 'action');
ok(in_array('created', $actions), 'Audit: created event present');
ok(in_array('submitted', $actions), 'Audit: submitted event present');
ok(in_array('approved', $actions), 'Audit: approved event present');
ok(in_array('posted', $actions), 'Audit: posted event present');
ok(count($audit) === 4, 'Exactly 4 audit events, one per action, no duplicates', 'got ' . count($audit));

echo "\n=== 8. Rejection path (separate voucher) ===\n";
$id2 = $model->createDraft([
    'voucher_type' => 'credit', 'voucher_date' => '2026-08-15',
    'primary_account_id' => 72, 'contra_account_id' => 7,
    'narration' => 'Test voucher to be rejected', 'amount' => 5000,
], $preparerId);
$model->submit($id2, $preparerId);
$model->reject($id2, $approverId, 'Missing supporting documentation');
$v2 = $model->find($id2);
ok($v2['status'] === 'rejected', 'Status transitions to rejected');
ok($v2['rejection_reason'] === 'Missing supporting documentation', 'Rejection reason stored');
ok((int)$v2['rejected_by'] === $approverId, 'rejected_by recorded');

echo "\n=== 9. Rejected voucher can be resubmitted ===\n";
$model->submit($id2, $preparerId);
$v2 = $model->find($id2);
ok($v2['status'] === 'pending_approval', 'Rejected voucher can be resubmitted for approval');
ok($v2['rejected_by'] === null && $v2['rejection_reason'] === null, 'Prior rejection metadata cleared on resubmit');

echo "\n=== 10. pendingApproval() model method (currently unused by any controller/view) ===\n";
$pending = $model->pendingApproval();
ok(count($pending) === 1, 'pendingApproval() correctly finds the 1 truly pending voucher', 'got ' . count($pending));

echo "\n=== 11. Notification check: does creating/submitting trigger any notification? ===\n";
$notifCount = (int)$db->query("SELECT COUNT(*) FROM notifications")->fetchColumn();
ok($notifCount === 0, 'CONFIRMED: zero notifications were created by any step above (no notification integration exists)');

echo "\nRESULTS: $pass passed, $fail failed\n";
