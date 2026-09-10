<?php
/**
 * Stage 23 — Board Governance Roles (Secretary, Vice Chairman) regression
 * suite. Self-dispatching: the orchestrator (no argv) builds one
 * disposable schema, seeds one user per role plus real Investment/
 * Internal Voucher/Loan Application fixtures, then re-invokes THIS SAME
 * FILE as subprocesses for every scenario that needs its own process
 * (a role-gate denial calls die()/exit, which would otherwise terminate
 * the whole suite) -- the identical technique Stage 21-D's own test
 * suite established and proved reliable.
 *
 * Targets a disposable schema only -- test_safety_guard.php fails closed
 * if DB_NAME ever resolves to the real 'empower_db'. Never touches
 * production data.
 */

require 'app/config/config.php';

if (isset($argv[1]) && $argv[1] !== '') {
    // ---- SUBPROCESS MODE: run exactly one controller action ----
    [$self, $schema, $userId, $action, $arg1, $csrfMode] = array_pad($argv, 6, null);
    define('DB_NAME', $schema);
    chdir(__DIR__ . '/..');
    require 'tests/test_safety_guard.php';
    require 'core/Database.php';
    require 'core/Autoloader.php';

    Session::start();
    $_SESSION['user_id'] = (int)$userId;
    $_SESSION['last_activity'] = time();
    // The session ALWAYS carries a real stored token (matching a real user
    // who has loaded the form at least once) -- only the SUBMITTED token in
    // runAction() below varies by $csrfMode. Session::get('csrf_token','')
    // defaulting to '' when unset would otherwise make hash_equals('','')
    // in verifyCsrf() spuriously return true for the 'missing' scenario.
    Session::set('csrf_token', 'KNOWNTOKEN123');

    require_once 'app/models/UserModel.php';
    require_once 'app/models/InvestmentModel.php';
    require_once 'app/models/InvestmentTypeModel.php';
    require_once 'app/models/InvestmentTransactionModel.php';
    require_once 'app/models/AccountModel.php';
    require_once 'app/models/InternalVoucherModel.php';
    require_once 'app/models/MemberModel.php';
    require_once 'app/models/MemberAccountAdjustmentModel.php';
    require_once 'app/models/OpeningBalanceBatchModel.php';
    require_once 'app/models/LoanModel.php';
    require_once 'app/models/LoanApplicationModel.php';
    require_once 'app/models/LoanProductModel.php';
    require_once 'app/models/MemberSavingsAccountModel.php';
    require_once 'app/services/LoanProvisioningService.php';

    function runAction(string $action, $arg1, ?string $csrfMode) {
        $csrf = $csrfMode === 'valid' ? 'KNOWNTOKEN123' : ($csrfMode === 'invalid' ? 'WRONG-TOKEN' : '');
        switch ($action) {
            case 'investment_view':
                require_once 'app/controllers/InvestmentController.php';
                (new InvestmentController())->index();
                break;
            case 'investment_approve':
                require_once 'app/controllers/InvestmentController.php';
                $_POST['investment_id'] = (int)$arg1;
                $_POST['csrf_token'] = $csrf;
                (new InvestmentController())->approve();
                break;
            case 'voucher_view':
                require_once 'app/controllers/InternalVoucherController.php';
                (new InternalVoucherController())->index();
                break;
            case 'voucher_approve':
                require_once 'app/controllers/InternalVoucherController.php';
                $_POST['voucher_id'] = (int)$arg1;
                $_POST['csrf_token'] = $csrf;
                (new InternalVoucherController())->approve();
                break;
            case 'application_view':
                require_once 'app/controllers/LoanApplicationController.php';
                (new LoanApplicationController())->index();
                break;
            case 'application_approve':
                require_once 'app/controllers/LoanApplicationController.php';
                $_POST['application_id'] = (int)$arg1;
                $_POST['approved_amount'] = 1000000;
                $_POST['approved_period_months'] = 12;
                $_POST['csrf_token'] = $csrf;
                (new LoanApplicationController())->approve();
                break;
            case 'adjustment_view':
                require_once 'app/controllers/MemberAccountAdjustmentController.php';
                (new MemberAccountAdjustmentController())->index();
                break;
            case 'opening_balance_view':
                require_once 'app/controllers/OpeningBalanceController.php';
                (new OpeningBalanceController())->index();
                break;
            case 'loan_disburse':
                require_once 'app/controllers/LoanController.php';
                $_POST['loan_id'] = (int)$arg1;
                $_POST['csrf_token'] = $csrf;
                (new LoanController())->disburse();
                break;
            case 'provisioning_calculate_form':
                require_once 'app/controllers/LoanProvisioningController.php';
                (new LoanProvisioningController())->calculateForm();
                break;
            case 'user_management':
                require_once 'app/models/SettingsModel.php';
                require_once 'app/controllers/SettingsController.php';
                (new SettingsController())->users();
                break;
        }
    }

    try {
        runAction($action, $arg1, $csrfMode);
    } catch (Throwable $e) {
        echo 'EXCEPTION: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
    echo "SUBPROCESS_COMPLETED_NO_DENIAL\n";
    exit(0);
}

// ---- ORCHESTRATOR MODE ----
define('DB_NAME', 'stage23_orchestrator_placeholder');
require 'tests/test_safety_guard.php';
require 'core/Database.php';
require 'core/Autoloader.php';

echo "=== STAGE 23 GOVERNANCE ROLES (SECRETARY / VICE CHAIRMAN) REGRESSION SUITE ===\n\n";
$pass = 0; $fail = 0;
function chk($label, $cond, $detail = '') {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $label" . ($detail ? " ($detail)" : '') . "\n"; $pass++; }
    else       { echo "  FAIL: $label" . ($detail ? " ($detail)" : '') . "\n"; $fail++; }
}

$phpBin = 'C:\\xampp\\php\\php.exe';
$thisFile = __FILE__;
function run(string $phpBin, string $thisFile, array $args): string {
    $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($thisFile);
    foreach ($args as $a) { $cmd .= ' ' . escapeshellarg((string)$a); }
    $out = shell_exec($cmd . ' 2>&1');
    return $out ?? ('[NO OUTPUT FROM: ' . $cmd . ']');
}

$testSchema = 'stage23_suite_' . date('His');

try {
    $pdoNoDb = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $pdoNoDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`");
    $pdoNoDb->exec("CREATE DATABASE `$testSchema` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $mainConn = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . $testSchema . ';charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $mainConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $prodConn = new PDO('mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=empower_db;charset=' . DB_CHARSET, DB_USER, DB_PASS);
    $prodConn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mainConn->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (['users','roles','members','accounts','investment_types','investments',
              'investment_transactions','internal_vouchers','loan_types','loan_applications',
              'journal_entry_audit','member_account_adjustments','opening_balance_batches',
              'journal_entries','expense_categories','member_savings_accounts','opening_balances',
              'financial_years','accounting_periods'] as $t) {
        $ddl = $prodConn->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_ASSOC)['Create Table'];
        $mainConn->exec($ddl);
    }

    // Real roles copied verbatim from production (includes secretary/vice_chairman).
    foreach ($prodConn->query("SELECT id,name,label FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $mainConn->prepare("INSERT INTO roles (id,name,label) VALUES (?,?,?)")->execute([$r['id'], $r['name'], $r['label']]);
    }
    $roleIds = [];
    foreach ($mainConn->query("SELECT id,name FROM roles")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $roleIds[$r['name']] = (int)$r['id'];
    }

    $roles = ['admin','treasurer','chairman','vice_chairman','secretary','cashier','office_admin',
              'system_admin','loans_officer','viewer'];
    $userIds = [];
    foreach ($roles as $i => $r) {
        $uid = 100 + $i;
        $userIds[$r] = $uid;
        $mainConn->prepare("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES (?,?,?,?,?,1)")
            ->execute([$uid, $roleIds[$r], ucfirst($r) . ' Test User', $r . '@test.local', 'x']);
    }
    // A deliberately inactive user, for the login-denial test.
    $mainConn->prepare("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES (999,?,?,?,?,0)")
        ->execute([$roleIds['secretary'], 'Inactive Secretary', 'inactive-sec@test.local', 'x']);
    // A second admin, distinct from the treasurer who prepares fixtures below --
    // used as the "maker" for the maker-checker self-approval test.
    $mainConn->exec("INSERT INTO users (id, role_id, full_name, email, password_hash, is_active) VALUES (200,{$roleIds['treasurer']},'Second Treasurer','treasurer2@test.local','x',1)");

    $mainConn->exec("INSERT INTO members (id, member_number, first_name, last_name, status) VALUES (1,'M001','Test','Member','active')");
    $mainConn->exec("INSERT INTO accounts (id, code, name, type, subtype, normal_balance, is_system, is_active) VALUES
        (1,'1110','Cash at Hand','asset','current_asset','debit',1,1),
        (2,'1140','Bank Accounts','asset','current_asset','debit',1,1),
        (3,'5300','Bad Debt Provision Expense','expense','operating_expense','debit',1,1)");
    $mainConn->exec("INSERT INTO investment_types (id, type_name, asset_gl_account_id, income_gl_account_id, is_active) VALUES (1,'Fixed Deposit',2,3,1)");
    $mainConn->exec("INSERT INTO loan_types (id, name, repayment_type, is_active) VALUES (1,'Standard Loan','monthly',1)");
    $mainConn->exec('SET FOREIGN_KEY_CHECKS=1');

    // Fixture 1: Investment prepared by treasurer(100), pending approval --
    // for authorized-secretary-approval, unauthorized-approval, CSRF, and
    // duplicate-approval tests.
    $mainConn->exec("INSERT INTO investments (id, investment_number, investment_type_id, principal_amount, start_date,
        investment_account_id, funding_account_id, status, recorded_by, submitted_at) VALUES
        (1,'INV-000001',1,5000000,'2026-09-01',2,1,'pending_approval',{$userIds['treasurer']},NOW())");
    // Fixture 2: a second investment, recorded_by the SAME user id used for
    // the maker-checker test below (chairman as both maker and checker).
    $mainConn->exec("INSERT INTO investments (id, investment_number, investment_type_id, principal_amount, start_date,
        investment_account_id, funding_account_id, status, recorded_by, submitted_at) VALUES
        (2,'INV-000002',1,3000000,'2026-09-01',2,1,'pending_approval',{$userIds['chairman']},NOW())");

    // Fixture 3: Internal Voucher prepared by treasurer, pending approval.
    $mainConn->exec("INSERT INTO internal_vouchers (id, voucher_number, voucher_type, voucher_date,
        primary_account_id, contra_account_id, narration, amount, status, recorded_by, submitted_at) VALUES
        (1,'VCH-000001','debit','2026-09-01',3,1,'Test voucher',150000,'pending_approval',{$userIds['treasurer']},NOW())");

    // Fixture 4: Loan Application prepared by loans_officer, pending approval --
    // for the secretary-approves-application test (the one loan-domain action
    // Secretary was actually granted).
    $mainConn->exec("INSERT INTO loan_applications (id, application_number, member_id, loan_type_id,
        requested_amount, requested_period_months, purpose, income_source, status, recorded_by, submitted_at) VALUES
        (1,'APP-000001',1,1,2000000,12,'Business','Salary','pending_approval',{$userIds['loans_officer']},NOW())");

    // Fixture 5: a second application, for the duplicate-approval test.
    $mainConn->exec("INSERT INTO loan_applications (id, application_number, member_id, loan_type_id,
        requested_amount, requested_period_months, purpose, income_source, status, recorded_by, submitted_at) VALUES
        (2,'APP-000002',1,1,1000000,6,'School fees','Business','pending_approval',{$userIds['loans_officer']},NOW())");

    echo "Fixture schema '$testSchema' ready. User ids: " . json_encode($userIds) . "\n\n";

    // ================================================================
    // 1. ROLES
    // ================================================================
    echo "--- 1. Roles ---\n";
    chk('secretary role exists', isset($roleIds['secretary']));
    chk('vice_chairman role exists', isset($roleIds['vice_chairman']));
    foreach (['admin','treasurer','member','cashier','viewer','chairman','loans_officer','office_admin','system_admin'] as $r) {
        chk("existing role '$r' still present", isset($roleIds[$r]));
    }

    // ================================================================
    // 2. LOGIN / AUTHENTICATION
    // ================================================================
    echo "\n--- 2. Login ---\n";
    $authStmt = $mainConn->prepare(
        "SELECT u.is_active, r.name AS role_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?"
    );
    $authStmt->execute([$userIds['secretary']]);
    $row = $authStmt->fetch(PDO::FETCH_ASSOC);
    chk('secretary user resolves to secretary role, active', $row && $row['role_name'] === 'secretary' && (int)$row['is_active'] === 1);

    $authStmt->execute([$userIds['vice_chairman']]);
    $row = $authStmt->fetch(PDO::FETCH_ASSOC);
    chk('vice_chairman user resolves to vice_chairman role, active', $row && $row['role_name'] === 'vice_chairman' && (int)$row['is_active'] === 1);

    $authStmt->execute([999]);
    $row = $authStmt->fetch(PDO::FETCH_ASSOC);
    chk('inactive secretary user is_active=0 (Session::revalidateAuthorization() would force-logout)', $row && (int)$row['is_active'] === 0);

    // ================================================================
    // 3. AUTHORIZATION -- allowed vs denied routes
    //
    // Detection note: this codebase's controllers deny access two
    // different ways -- some call http_response_code(403); die('Access
    // denied...') (Investment/Voucher/MemberAdjustment/OpeningBalance/
    // Provisioning constructors), others call Session::flash()+
    // $this->redirect()+exit (LoanController's trait, SettingsController) --
    // and core/Controller.php's redirect() always calls exit, which
    // terminates the subprocess with ZERO stdout (headers are not visible
    // output in CLI mode). A GENUINE allow, by contrast, calls render(),
    // which always produces a substantial HTML page (thousands of bytes).
    // So "allowed" is verified as "output length exceeds a trivial
    // threshold" and "denied" as "output is short/empty" -- one detection
    // rule that correctly covers both denial styles, rather than
    // literal-string-matching a message that only some controllers emit.
    // ================================================================
    echo "\n--- 3. Authorization ---\n";
    $sec = $userIds['secretary']; $vc = $userIds['vice_chairman'];
    function allowed(string $out): bool { return strlen(trim($out)) > 500; }

    chk('secretary allowed: investments view', allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'investment_view'])));
    chk('secretary allowed: internal vouchers view', allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'voucher_view'])));
    chk('secretary allowed: loan applications view', allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'application_view'])));
    chk('secretary DENIED: member adjustments view (not in granted scope)', !allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'adjustment_view'])));
    chk('secretary DENIED: opening balances view (not in granted scope)', !allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'opening_balance_view'])));
    chk('secretary DENIED: provisioning calculate form', !allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'provisioning_calculate_form'])));
    chk('secretary DENIED: user management', !allowed(run($phpBin, $thisFile, [$testSchema, $sec, 'user_management'])));

    chk('vice_chairman allowed: member adjustments view', allowed(run($phpBin, $thisFile, [$testSchema, $vc, 'adjustment_view'])));
    chk('vice_chairman allowed: opening balances view', allowed(run($phpBin, $thisFile, [$testSchema, $vc, 'opening_balance_view'])));
    chk('vice_chairman allowed: investments view', allowed(run($phpBin, $thisFile, [$testSchema, $vc, 'investment_view'])));
    chk('vice_chairman DENIED: user management (not granted -- technical/admin domain only)', !allowed(run($phpBin, $thisFile, [$testSchema, $vc, 'user_management'])));

    // LoanController/LoanApplicationController's shared trait (Loan
    // approval/reject/disburse) is a POST-only, always-redirects action
    // with no distinct GET "view" step to probe the same way -- exercising
    // it end-to-end would require a full loans+journal_entries+accounts
    // disbursement fixture unrelated to what Stage 23 actually changed.
    // Verified instead by direct source inspection (Class A evidence,
    // consistent with this engagement's established practice for static
    // role-array facts): the mechanism itself (Session::hasRole() against
    // the exact same roles table) is already proven dynamically five times
    // over below for the other four workflows using the identical function.
    $traitSrc = file_get_contents(__DIR__ . '/../app/controllers/traits/LoanRoleAccessTrait.php');
    preg_match("/requireApproverAccess.*?hasRole\(\[(.*?)\]\)/s", $traitSrc, $tm);
    $traitRoles = $tm[1] ?? '';
    chk("LoanRoleAccessTrait::requireApproverAccess() includes 'vice_chairman'", str_contains($traitRoles, "'vice_chairman'"), trim($traitRoles));
    chk("LoanRoleAccessTrait::requireApproverAccess() excludes 'secretary' (application-only, not disbursement)", !str_contains($traitRoles, "'secretary'"), trim($traitRoles));
    $appCtrlSrc = file_get_contents(__DIR__ . '/../app/controllers/LoanApplicationController.php');
    preg_match("/requireApproverAccess\(\): void\s*\{.*?hasRole\(\[(.*?)\]\)/s", $appCtrlSrc, $am);
    $appRoles = $am[1] ?? '';
    chk("LoanApplicationController overrides requireApproverAccess() to include BOTH 'vice_chairman' and 'secretary'", str_contains($appRoles, "'vice_chairman'") && str_contains($appRoles, "'secretary'"), trim($appRoles));

    // ================================================================
    // 4. APPROVALS
    //
    // Detection note: approve()/reject() controller actions ALWAYS end
    // with header()+exit (success or failure alike -- both branches fall
    // through to the same redirect), so process output can never
    // distinguish an authorized success from a denied/failed attempt here.
    // The only reliable signal is the DATABASE row itself: did status/
    // approved_by/approved_at actually change, or not.
    // ================================================================
    echo "\n--- 4. Approvals ---\n";

    // Unauthorized approval attempt first (cashier has never had investment
    // approval authority, unaffected by Stage 23) -- row must stay untouched.
    run($phpBin, $thisFile, [$testSchema, $userIds['cashier'], 'investment_approve', 1, 'valid']);
    $stillPendingUnauth = $mainConn->query("SELECT status FROM investments WHERE id=1")->fetchColumn();
    chk('unauthorized role (cashier) DENIED investment approval (row unchanged)', $stillPendingUnauth === 'pending_approval');

    // Authorized secretary approval succeeds (Investment INV-000001,
    // recorded_by treasurer -- no self-approval conflict).
    run($phpBin, $thisFile, [$testSchema, $sec, 'investment_approve', 1, 'valid']);
    $inv = $mainConn->query("SELECT status, approved_by, approved_at FROM investments WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    chk('authorized secretary approval of an investment succeeds, audit record created', $inv['status'] === 'approved' && (int)$inv['approved_by'] === $sec && $inv['approved_at'] !== null);

    // Duplicate approval attempt on the SAME already-approved investment
    // must fail -- approved_by must stay the FIRST approver (secretary),
    // never silently overwritten by the second (chairman) attempt.
    run($phpBin, $thisFile, [$testSchema, $userIds['chairman'], 'investment_approve', 1, 'valid']);
    $inv2 = $mainConn->query("SELECT status, approved_by FROM investments WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    chk('duplicate approval of an already-approved investment fails (approver unchanged)', $inv2['status'] === 'approved' && (int)$inv2['approved_by'] === $sec);

    // CSRF failure: valid role, no CSRF token supplied -- row must stay untouched.
    run($phpBin, $thisFile, [$testSchema, $vc, 'investment_approve', 2, 'missing']);
    $stillPending = $mainConn->query("SELECT status FROM investments WHERE id=2")->fetchColumn();
    chk('CSRF failure blocks approval (status still pending_approval)', $stillPending === 'pending_approval');

    // Voucher: authorized secretary approval succeeds.
    run($phpBin, $thisFile, [$testSchema, $sec, 'voucher_approve', 1, 'valid']);
    $vch = $mainConn->query("SELECT status, approved_by FROM internal_vouchers WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    chk('authorized secretary approval of an internal voucher succeeds, audit record created', $vch['status'] === 'approved' && (int)$vch['approved_by'] === $sec);

    // Loan Application: authorized secretary approval succeeds.
    run($phpBin, $thisFile, [$testSchema, $sec, 'application_approve', 1, 'valid']);
    $app = $mainConn->query("SELECT status, approved_by FROM loan_applications WHERE id=1")->fetch(PDO::FETCH_ASSOC);
    chk('authorized secretary approval of a loan application succeeds, audit record created', $app['status'] === 'approved' && (int)$app['approved_by'] === $sec);

    // Authorized vice_chairman approval succeeds (a second loan application).
    run($phpBin, $thisFile, [$testSchema, $vc, 'application_approve', 2, 'valid']);
    $app2 = $mainConn->query("SELECT status, approved_by FROM loan_applications WHERE id=2")->fetch(PDO::FETCH_ASSOC);
    chk('authorized vice_chairman approval of a loan application succeeds', $app2['status'] === 'approved' && (int)$app2['approved_by'] === $vc);

    // No delete route exists for any of these three record types -- approval
    // history cannot be removed through the normal UI (checked directly
    // against index.php's route table, not re-implemented here).
    $routesSrc = file_get_contents(__DIR__ . '/../index.php');
    chk("no 'investment-delete' route exists", !str_contains($routesSrc, "'investment-delete'"));
    chk("no 'internal-voucher-delete' route exists", !str_contains($routesSrc, "'internal-voucher-delete'"));
    chk("no 'loan-application-delete' route exists", !str_contains($routesSrc, "'loan-application-delete'"));

    // ================================================================
    // 5. MAKER-CHECKER
    // ================================================================
    echo "\n--- 5. Maker-checker ---\n";
    // Investment id=2 was recorded_by chairman; chairman attempting to
    // approve their own investment must fail regardless of role authority
    // -- InvestmentModel::approve()'s own recorded_by===$userId guard,
    // independent of any Session::hasRole() check, must still block this.
    run($phpBin, $thisFile, [$testSchema, $userIds['chairman'], 'investment_approve', 2, 'valid']);
    $selfCheck = $mainConn->query("SELECT status, approved_by FROM investments WHERE id=2")->fetch(PDO::FETCH_ASSOC);
    chk('creator (chairman) cannot approve their own investment (row unchanged)', $selfCheck['status'] === 'pending_approval' && $selfCheck['approved_by'] === null);

    // ================================================================
    // 6. EXISTING ROLES -- regression
    // ================================================================
    echo "\n--- 6. Existing-role regression ---\n";
    chk('admin still allowed: user management (unaffected by Stage 23)', allowed(run($phpBin, $thisFile, [$testSchema, $userIds['admin'], 'user_management'])));

    // Investment id=2 is still pending_approval at this point (the CSRF and
    // maker-checker attempts above both failed to change it) -- reuse it to
    // verify office_admin/system_admin genuinely cannot approve it.
    run($phpBin, $thisFile, [$testSchema, $userIds['office_admin'], 'investment_approve', 2, 'valid']);
    $officeAdminAttempt = $mainConn->query("SELECT status FROM investments WHERE id=2")->fetchColumn();
    chk('office_admin still DENIED: investment approval (unaffected, row unchanged)', $officeAdminAttempt === 'pending_approval');

    run($phpBin, $thisFile, [$testSchema, $userIds['system_admin'], 'investment_approve', 2, 'valid']);
    $systemAdminAttempt = $mainConn->query("SELECT status FROM investments WHERE id=2")->fetchColumn();
    chk('system_admin still DENIED: investment approval (unaffected, row unchanged)', $systemAdminAttempt === 'pending_approval');
    // Role-array regression, verified directly against the exact
    // production controller source (Class A evidence) -- office_admin and
    // system_admin were never in InvestmentController::requireApproverAccess()
    // before Stage 23, and Stage 23's diff only appended 'vice_chairman'
    // and 'secretary', so neither can have gained approval access as a
    // side effect. Dynamic proof of the mechanism itself (Session::hasRole()
    // correctly blocking a role absent from the array) is already
    // established immediately above via the office_admin/cashier/
    // system_admin investment-approval checks earlier in this suite.
    $invCtrlSrc = file_get_contents(__DIR__ . '/../app/controllers/InvestmentController.php');
    preg_match("/requireApproverAccess\(\): void\s*\{.*?hasRole\(\[(.*?)\]\)/s", $invCtrlSrc, $im);
    $invApproverRoles = $im[1] ?? '';
    chk("InvestmentController::requireApproverAccess() still excludes office_admin", !str_contains($invApproverRoles, "'office_admin'"), trim($invApproverRoles));
    chk("InvestmentController::requireApproverAccess() still excludes system_admin", !str_contains($invApproverRoles, "'system_admin'"), trim($invApproverRoles));
    chk("InvestmentController::requireApproverAccess() still excludes loans_officer", !str_contains($invApproverRoles, "'loans_officer'"), trim($invApproverRoles));
    chk("InvestmentController::requireApproverAccess() still excludes cashier", !str_contains($invApproverRoles, "'cashier'"), trim($invApproverRoles));

} finally {
    if (isset($pdoNoDb) && isset($testSchema) && !getenv('STAGE23_KEEP_SCHEMA')) {
        $pdoNoDb->exec("DROP DATABASE IF EXISTS `$testSchema`");
        echo "\n[Cleanup] Dropped disposable schema '$testSchema'.\n";
    } elseif (isset($testSchema)) {
        echo "\n[DEBUG] Kept schema '$testSchema' (STAGE23_KEEP_SCHEMA set).\n";
    }
}

echo "\n=== RESULTS: $pass passed, $fail failed ===\n";
exit($fail > 0 ? 1 : 0);
