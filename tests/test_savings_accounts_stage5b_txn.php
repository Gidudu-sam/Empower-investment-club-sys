<?php
/**
 * Stage 5B controller-level scenario helper. Invoked as a SEPARATE PHP
 * process (once per scenario) by test_savings_accounts_stage5b.php.
 *
 * Two things force this out-of-process, one-scenario-per-run design:
 *  1) The controller's render()-based paths use the default 'main'
 *     layout, which `include`s (not `include_once`s) sidebar.php, and a
 *     second full-layout render() in one process fatals on a duplicate
 *     function declaration -- same reason as Stage 4's render helper.
 *  2) depositStore()/withdrawalStore() end in redirect()->exit(), which
 *     truly terminates the process -- there is no way to "return" from
 *     it. register_shutdown_function() is used to run the scenario's
 *     assertions and roll back the transaction AFTER exit() fires,
 *     since shutdown functions still run at that point (the same
 *     technique used for the MemberController::add() smoke test in
 *     Stage 3).
 *
 * Each scenario creates its own minimal fixture data inside its own
 * transaction and rolls back before exiting -- zero permanent writes.
 * A scenario needing two sequential store() calls is not possible here
 * (the first exit() ends the process) -- that kind of multi-step
 * accounting check is done in-process against SavingsModel directly in
 * the main Stage 5B suite instead.
 */

require 'app/config/config.php';
require 'test_safety_guard.php'; // Stage 27: was 'app/config/database.php' -- see test_safety_guard.php
require 'core/Database.php';
require 'core/Autoloader.php';
require 'core/Session.php';

$scenario = $argv[1] ?? '';
$role     = $argv[2] ?? 'admin';
$db = Database::getInstance()->getConnection();

Session::set('user_id', 1);
Session::set('user_role', $role);

$db->beginTransaction();

$accountModel = new MemberSavingsAccountModel();
$memberModel  = new MemberModel();

$counter = 950000 + random_int(1, 99999);
function makeMember(MemberModel $memberModel, int &$counter): array {
    $counter++;
    return $memberModel->createWithCompulsoryAccount([
        'member_number' => 'STAGE5B-' . $counter,
        'first_name'    => 'Stage5B',
        'last_name'     => 'Test' . $counter,
        'gender'        => 'Male',
        'phone'         => '07' . str_pad((string)$counter, 8, '0', STR_PAD_LEFT),
        'national_id'   => 'S5BID' . $counter,
        'station'       => 'Test Station',
        'join_date'     => '2026-08-01',
        'status'        => 'active',
        'created_by'    => 1,
    ], 1);
}

/** Registered once; runs whichever check closure was set, then rolls back and prints the verdict, whether we got here via exit() or a normal return. */
$GLOBALS['__check'] = null;
register_shutdown_function(function () use ($db, $scenario) {
    $pass = false;
    $notes = ['no check registered'];
    if (is_callable($GLOBALS['__check'] ?? null)) {
        try {
            [$pass, $notes] = ($GLOBALS['__check'])();
        } catch (Throwable $e) {
            $pass = false;
            $notes = ['EXCEPTION during check: ' . $e->getMessage()];
        }
    }
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    echo ($pass ? "SCENARIO_PASS" : "SCENARIO_FAIL") . " [{$scenario}] " . implode('; ', $notes) . "\n";
});

try {
    switch ($scenario) {

        case 'inactive_account_rejected': {
            $m = makeMember($memberModel, $counter);
            $accountModel->updateStatus($m['account_id'], 'dormant');
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $m['account_id'], 'amount' => '1000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["dormant account correctly rejected a deposit, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'nonexistent_account': {
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => '999999999', 'amount' => '1000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["nonexistent account_id correctly rejected, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'csrf_mismatch': {
            $m = makeMember($memberModel, $counter);
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'real-token';
            $_POST = ['csrf_token' => 'WRONG-token', 'account_id' => $m['account_id'], 'amount' => '1000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["CSRF mismatch correctly rejected, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'unauthorized_role': {
            // Invoked with argv[2] = 'viewer'.
            $m = makeMember($memberModel, $counter);
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $m['account_id'], 'amount' => '1000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["viewer role correctly denied write access (403 die(), no row created), before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore(); // constructor die()s before this line's effects matter
            ob_end_clean();
            break;
        }

        case 'corporate_deposit_rejected': {
            $org = (new OrganizationModel())->createOrganization(['name' => 'Stage5B Test Org']);
            $accId = $accountModel->createAccount(['account_type' => 'corporate', 'opened_date' => '2026-08-20'], [['organization_id' => $org, 'role' => 'organization']], 1);
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $accId, 'amount' => '1000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["corporate account deposit correctly rejected (documented gap), before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'joint_deposit_valid': {
            $m1 = makeMember($memberModel, $counter);
            $m2 = makeMember($memberModel, $counter);
            $jointId = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], [
                ['member_id' => $m1['member_id'], 'role' => 'primary'],
                ['member_id' => $m2['member_id'], 'role' => 'joint'],
            ], 1);
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $jointId, 'member_id' => $m2['member_id'], 'amount' => '15000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $jointId, $m2) {
                $row = $db->query("SELECT * FROM savings WHERE savings_account_id = {$jointId} ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                $ok = $row && (int)$row['member_id'] === $m2['member_id'] && (float)$row['credit'] === 15000.0;
                return [$ok, ['joint deposit by a valid non-primary holder recorded with correct member_id and amount']];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'joint_deposit_invalid_holder': {
            $m1 = makeMember($memberModel, $counter);
            $m2 = makeMember($memberModel, $counter);
            $outsider = makeMember($memberModel, $counter);
            $jointId = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], [
                ['member_id' => $m1['member_id'], 'role' => 'primary'],
                ['member_id' => $m2['member_id'], 'role' => 'joint'],
            ], 1);
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $jointId, 'member_id' => $outsider['member_id'], 'amount' => '15000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["joint deposit by a non-holder member correctly rejected, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'joint_deposit_missing_member': {
            $m1 = makeMember($memberModel, $counter);
            $m2 = makeMember($memberModel, $counter);
            $jointId = $accountModel->createAccount(['account_type' => 'joint', 'opened_date' => '2026-08-20'], [
                ['member_id' => $m1['member_id'], 'role' => 'primary'],
                ['member_id' => $m2['member_id'], 'role' => 'joint'],
            ], 1);
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $jointId, 'amount' => '15000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20']; // no member_id
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["joint deposit with no member_id posted correctly rejected, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->depositStore();
            ob_end_clean();
            break;
        }

        case 'withdrawal_insufficient_balance': {
            $m = makeMember($memberModel, $counter); // fresh account, balance = 0
            $before = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
            $_SESSION['csrf_token'] = 'tok';
            $_POST = ['csrf_token' => 'tok', 'account_id' => $m['account_id'], 'amount' => '5000', 'payment_method' => 'Cash', 'transaction_date' => '2026-08-20'];
            $GLOBALS['__check'] = function () use ($db, $before) {
                $after = (int)$db->query('SELECT COUNT(*) FROM savings')->fetchColumn();
                return [$after === $before, ["withdrawal exceeding available balance (0.00) correctly rejected, before={$before} after={$after}"]];
            };
            ob_start();
            (new SavingsAccountController())->withdrawalStore();
            ob_end_clean();
            break;
        }

        default:
            $GLOBALS['__check'] = fn() => [false, ["unknown scenario '{$scenario}'"]];
    }
} catch (Throwable $e) {
    $GLOBALS['__check'] = fn() => [false, ['EXCEPTION before store() call: ' . $e->getMessage()]];
}
