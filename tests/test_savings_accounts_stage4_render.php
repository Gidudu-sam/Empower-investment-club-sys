<?php
/**
 * Stage 4 render-method smoke-test helper. Invoked as a SEPARATE PHP
 * process (once per scenario) by test_savings_accounts_stage4.php.
 *
 * Why a separate process per scenario: SavingsAccountController's
 * render()-based methods use the default 'main' layout, which
 * `include`s app/views/layouts/sidebar.php (not `include_once`).
 * sidebar.php declares a top-level isActive() function, so a SECOND
 * full-layout render() call within the same PHP process fatals with
 * "Cannot redeclare isActive()". This is a real, pre-existing latent
 * bug in main.php/sidebar.php (include vs include_once) — it never
 * manifests in normal use since each HTTP request is its own PHP
 * process, so it is reported (not fixed) in the Stage 4 report rather
 * than patched here, per the established "report, don't silently fix
 * unrelated issues" discipline for this module. Running one scenario
 * per process sidesteps it cleanly while still exercising the exact
 * same render() code path a real request would.
 *
 * Every scenario creates its own minimal fixture data inside its own
 * transaction and rolls back before exiting — zero permanent writes.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$scenario = $argv[1] ?? '';
$db = Database::getInstance()->getConnection();

Session::set('user_id', 1);
Session::set('user_role', 'admin');

$db->beginTransaction();
$pass = true;
$notes = [];

try {
    $accountModel = new MemberSavingsAccountModel();
    $organizationModel = new OrganizationModel();
    $controller = new SavingsAccountController();
    $memberIds = array_column($db->query('SELECT id FROM members ORDER BY id LIMIT 6')->fetchAll(), 'id');
    [$m1, $m2, $m3] = array_map('intval', $memberIds);

    switch ($scenario) {
        case 'overview':
            $_GET = [];
            ob_start();
            $controller->overview();
            $out = ob_get_clean();
            $pass = str_contains($out, 'Savings Accounts')
                && str_contains($out, 'Compulsory Savings') && str_contains($out, 'Voluntary Savings')
                && str_contains($out, 'Joint Savings') && str_contains($out, 'Corporate Savings');
            $notes[] = 'renders overview with all 4 type rows';
            break;

        case 'openSelect':
            ob_start();
            $controller->openSelect();
            $out = ob_get_clean();
            $pass = str_contains($out, 'savings-account-voluntary') && str_contains($out, 'savings-account-joint')
                && str_contains($out, 'savings-account-corporate') && str_contains($out, 'Automatic');
            $notes[] = 'openSelect lists Voluntary/Joint/Corporate, Compulsory marked automatic';
            break;

        case 'voluntaryForm':
            ob_start();
            $controller->voluntaryForm();
            $out = ob_get_clean();
            $pass = str_contains($out, 'member_id');
            $notes[] = 'voluntaryForm includes member dropdown';
            break;

        case 'jointForm':
            ob_start();
            $controller->jointForm();
            $out = ob_get_clean();
            $pass = substr_count($out, 'holder-row') >= 2;
            $notes[] = 'jointForm includes two starter holder rows';
            break;

        case 'corporateForm':
            ob_start();
            $controller->corporateForm();
            $out = ob_get_clean();
            $pass = str_contains($out, 'orgModeNew') && str_contains($out, 'orgModeExisting') && str_contains($out, 'rep_full_name');
            $notes[] = 'corporateForm includes org create/select toggle and representatives section';
            break;

        case 'view_voluntary':
            $id = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m1, 'role' => 'primary']], 1);
            $acc = $accountModel->getAccount($id);
            $_GET = ['id' => $id];
            ob_start();
            $controller->view();
            $out = ob_get_clean();
            // Stage 5B intentionally enables Record Deposit/Withdrawal for an
            // active, non-corporate account (was a disabled placeholder in
            // Stage 4, before the live transaction workflow existed) --
            // updated here to match, per the Stage 5B report.
            $pass = str_contains($out, $acc['account_number']) && str_contains($out, 'savings-account-deposit') && str_contains($out, 'Record Deposit');
            $notes[] = 'view() renders voluntary account, shows ENABLED deposit/withdrawal actions (Stage 5B)';
            break;

        case 'view_joint':
            $id = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], [
                ['member_id' => $m1, 'role' => 'primary'],
                ['member_id' => $m2, 'role' => 'joint'],
            ], 1);
            $acc = $accountModel->getAccount($id);
            $_GET = ['id' => $id];
            ob_start();
            $controller->view();
            $out = ob_get_clean();
            $pass = str_contains($out, $acc['account_number']);
            $notes[] = 'view() renders joint account';
            break;

        case 'view_corporate':
            $orgId = $organizationModel->createOrganization(['name' => 'Render Test Org Ltd']);
            $organizationModel->addRepresentative($orgId, ['full_name' => 'Render Rep', 'is_authorized_signatory' => true]);
            $id = $accountModel->createAccount(['account_type' => 'corporate', 'opened_date' => '2026-08-20'], [['organization_id' => $orgId, 'role' => 'organization']], 1);
            $_GET = ['id' => $id];
            ob_start();
            $controller->view();
            $out = ob_get_clean();
            $pass = str_contains($out, 'Render Test Org Ltd') && str_contains($out, 'Render Rep');
            $notes[] = 'view() renders corporate account with organization + representatives';
            break;

        case 'statement':
            $id = $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m1, 'role' => 'primary']], 1);
            $acc = $accountModel->getAccount($id);
            $_GET = ['id' => $id];
            ob_start();
            $controller->statement();
            $out = ob_get_clean();
            $pass = str_contains($out, '<!DOCTYPE html>') && !str_contains($out, 'sb-sidenav') && str_contains($out, $acc['account_number']);
            $notes[] = 'statement() is a standalone printable document (layout=null), shows account number';
            break;

        case 'memberSummary':
            $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m1, 'role' => 'primary']], 1);
            $accountModel->createAccount(['account_type' => 'voluntary', 'opened_date' => '2026-08-20'], [['member_id' => $m1, 'role' => 'primary']], 1);
            $_GET = ['member_id' => $m1];
            ob_start();
            $controller->memberSummary();
            $out = ob_get_clean();
            $summary = $accountModel->getMemberSavingsSummary($m1);
            $pass = count($summary['accounts']) >= 2 && $summary['total'] === 0.0 && !empty($out);
            $notes[] = 'memberSummary() lists every account this member holds, grand total is a display-only sum';
            break;

        default:
            $pass = false;
            $notes[] = "unknown scenario '{$scenario}'";
    }
} catch (Throwable $e) {
    $pass = false;
    $notes[] = 'EXCEPTION: ' . $e->getMessage();
} finally {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
}

echo $pass ? "SCENARIO_PASS" : "SCENARIO_FAIL";
echo ' [' . $scenario . '] ' . implode('; ', $notes) . "\n";
exit($pass ? 0 : 1);
