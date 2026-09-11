<?php
/**
 * Smart Member Import — Test Harness
 *
 * TARGETS AN ISOLATED, DISPOSABLE CLONE (name passed as argv[1]). NEVER
 * touches empower_db.
 *
 * Verifies (all new/changed behavior in MemberImportController.php):
 *  - findColumn()/mapColumns() fuzzy header matching against the user's
 *    actual spreadsheet headers ("Name in Full", "ACCOUNT NUMBER",
 *    "Official personal No(NIN)", "Next of Kin Name and Address", etc.)
 *  - suggestMapping() produces sane field->header suggestions for the
 *    Column Mapping Confirmation UI
 *  - coerceRow() full_name -> first/last split and next_of_kin_full ->
 *    next_of_kin_name fallback
 *  - guessGender() English-name dictionary inference (never silent)
 *  - validateRows(): gender is now REQUIRED (no more silent 'Male'
 *    default at any of the 3 old sites), account_number format/length/
 *    uniqueness validation, next_of_kin_address length check
 *  - process()-equivalent commit: account_number from file is respected;
 *    blank account_number auto-generates via generateAccountNumber();
 *    every imported member gets a compulsory savings account
 *    (createWithCompulsoryAccount, not the old bare create()); a single
 *    row's failure (duplicate account_number / duplicate compulsory
 *    account) is isolated via SAVEPOINT without losing the rest of the
 *    batch or leaving an orphaned member row.
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
require_once CORE_PATH . '/Controller.php';
require_once CORE_PATH . '/Autoloader.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/controllers/MemberImportController.php';

echo "=== TARGET DATABASE: " . DB_NAME . " (disposable clone -- never empower_db) ===\n";
$pdo = Database::getInstance()->getConnection();

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  [PASS] $label\n"; }
    else { $fail++; echo "  [FAIL] $label -- $detail\n"; }
}
function section(string $t): void { echo "\n=== $t ===\n"; }

$controller = new MemberImportController();
$ref = new ReflectionClass($controller);
function call($controller, ReflectionClass $ref, string $method, array $args) {
    $m = $ref->getMethod($method);
    $m->setAccessible(true);
    return $m->invokeArgs($controller, $args);
}

// ================================================================
section('A: mapColumns() against the user\'s actual spreadsheet headers');
// ================================================================
// Exact header row the user shared (lowercased/trimmed, as parseCSV() would produce)
$userHeaderRow = [
    'row_num' => 2,
    'name in full' => 'Ssali Frank',
    'account number' => '',
    'date joined' => '2024-01-15',
    'date of birth' => '1990-05-20',
    "official personal no(nin)" => 'CM12345678ABCD',
    'station' => 'Kampala',
    'present address' => 'Kampala',
    'email adress' => 'ssali@example.com',
    'home address' => 'Mukono',
    'mobile no' => '0771234567',
    'next of kin name and address' => 'Nakato Mary, Mukono',
    "next of kin's relation to member" => 'Sister',
    "next of kin's contact" => '0709876543',
];
$mapped = call($controller, $ref, 'mapColumns', [$userHeaderRow]);
check('Full Name column ("Name in Full") is detected and passed through as full_name', $mapped['full_name'] === 'Ssali Frank', var_export($mapped['full_name'], true));
check('first_name/last_name are blank at mapColumns() stage (split happens in coerceRow())', $mapped['first_name'] === '' && $mapped['last_name'] === '');
check('Blank ACCOUNT NUMBER column maps to empty string (not system-generated at this stage)', $mapped['account_number'] === '');
check('National ID (NIN) column "Official personal No(NIN)" detected', $mapped['national_id'] === 'CM12345678ABCD', var_export($mapped['national_id'], true));
check('Mobile No. detected', $mapped['phone'] === '0771234567');
check('Email Adress (misspelled) detected via substring fallback', $mapped['email'] === 'ssali@example.com', var_export($mapped['email'], true));
check('Combined "Next of Kin Name and Address" lands whole in next_of_kin_name', $mapped['next_of_kin_name'] === 'Nakato Mary, Mukono', var_export($mapped['next_of_kin_name'], true));
check('next_of_kin_address is left blank when only the combined column exists (no unsafe auto-split)', $mapped['next_of_kin_address'] === '');
check("Next of Kin's Contact detected", $mapped['next_of_kin_phone'] === '0709876543');
check("Next of Kin's Relation to Member detected", $mapped['next_of_kin_relation'] === 'Sister');
check('Gender is NOT defaulted to Male when no Gender/Sex column exists at all', $mapped['gender'] === '');
check('Join Date ("date joined") detected', $mapped['join_date'] === '2024-01-15');

// ================================================================
section('B: coerceRow() full_name splitting + next_of_kin_full fallback');
// ================================================================
$coerced = call($controller, $ref, 'coerceRow', [$mapped]);
check('full_name "Ssali Frank" splits to first_name=Ssali', $coerced['first_name'] === 'Ssali', var_export($coerced['first_name'], true));
check('full_name "Ssali Frank" splits to last_name=Frank', $coerced['last_name'] === 'Frank', var_export($coerced['last_name'], true));
check('gender stays blank through coerceRow() (2nd former silent-default site closed)', $coerced['gender'] === '', var_export($coerced['gender'], true));
check('account_number stays blank through coerceRow()', $coerced['account_number'] === '');

// A row with only a combined next_of_kin_full key (client-mapped JSON path)
$kinOnly = call($controller, $ref, 'coerceRow', [['next_of_kin_full' => 'Auntie Betty, Jinja Road']]);
check('next_of_kin_full alone lands in next_of_kin_name via coerceRow()', $kinOnly['next_of_kin_name'] === 'Auntie Betty, Jinja Road');

// ================================================================
section('C: guessGender() — English/Western name dictionary only');
// ================================================================
check('guessGender("David") => Male', call($controller, $ref, 'guessGender', ['David']) === 'Male');
check('guessGender("Grace") => Female', call($controller, $ref, 'guessGender', ['Grace']) === 'Female');
check('guessGender("Nakato") => null (Luganda name, not in English dictionary — no fabricated guess)', call($controller, $ref, 'guessGender', ['Nakato']) === null);
check('guessGender("") => null', call($controller, $ref, 'guessGender', ['']) === null);

// ================================================================
section('D: suggestMapping() — Column Mapping Confirmation pre-fill');
// ================================================================
$headers = array_keys($userHeaderRow);
$suggested = call($controller, $ref, 'suggestMapping', [$headers]);
check('suggestMapping maps full_name -> "name in full"', ($suggested['full_name'] ?? null) === 'name in full', var_export($suggested['full_name'] ?? null, true));
check('suggestMapping maps national_id -> the NIN column', ($suggested['national_id'] ?? null) === 'official personal no(nin)', var_export($suggested['national_id'] ?? null, true));
check('suggestMapping maps phone -> "mobile no"', ($suggested['phone'] ?? null) === 'mobile no');
check('suggestMapping does NOT also map first_name to "name in full" (already claimed by full_name)', ($suggested['first_name'] ?? '') === '', var_export($suggested['first_name'] ?? null, true));
check('suggestMapping maps join_date -> "date joined"', ($suggested['join_date'] ?? null) === 'date joined');
check('suggestMapping leaves gender unmapped (no Gender/Sex column present)', !isset($suggested['gender']) || $suggested['gender'] === '');

// ================================================================
section('E: validateRows() — gender now required, account_number validated');
// ================================================================
$memberModel = new MemberModel();

$rowsToValidate = [
    // E1: valid row, blank gender -> must error with a suggestion (David -> Male)
    array_merge($coerced, ['row_num' => 1, 'first_name' => 'David', 'last_name' => 'Test1', 'phone' => '0700111001', 'national_id' => 'SMI0001', 'gender' => '']),
    // E2: same but gender explicitly supplied -> ready
    array_merge($coerced, ['row_num' => 2, 'first_name' => 'David', 'last_name' => 'Test2', 'phone' => '0700111002', 'national_id' => 'SMI0002', 'gender' => 'Male']),
    // E3: invalid account number format
    array_merge($coerced, ['row_num' => 3, 'first_name' => 'Grace', 'last_name' => 'Test3', 'phone' => '0700111003', 'national_id' => 'SMI0003', 'gender' => 'Female', 'account_number' => 'BAD NUM!!']),
    // E4: valid custom account number
    array_merge($coerced, ['row_num' => 4, 'first_name' => 'Grace', 'last_name' => 'Test4', 'phone' => '0700111004', 'national_id' => 'SMI0004', 'gender' => 'Female', 'account_number' => 'SMI-ACC-004']),
];
$result = call($controller, $ref, 'validateRows', [$rowsToValidate]);
$byRow = [];
foreach ($result['rows'] as $r) $byRow[$r['row_num']] = $r;

check('E1: blank gender -> status=error', $byRow[1]['status'] === 'error', json_encode($byRow[1]));
check('E1: error message mentions Gender missing', str_contains($byRow[1]['error'], 'Gender missing'), $byRow[1]['error']);
check('E1: suggested_gender = Male for "David"', $byRow[1]['suggested_gender'] === 'Male', var_export($byRow[1]['suggested_gender'], true));
check('E2: explicit gender -> status=ready', $byRow[2]['status'] === 'ready', json_encode($byRow[2]));
check('E3: invalid account number format -> status=error', $byRow[3]['status'] === 'error');
check('E3: error mentions account number format', str_contains($byRow[3]['error'], 'account number'), $byRow[3]['error']);
check('E4: valid custom account number -> status=ready', $byRow[4]['status'] === 'ready', json_encode($byRow[4]));

// Uniqueness: reserve SMI-ACC-004 for real, then re-validate an unrelated row using it
section('E-continued: account_number uniqueness against the DB');
$dupCheckRows = [array_merge($coerced, ['row_num' => 5, 'first_name' => 'Grace', 'last_name' => 'Test5', 'phone' => '0700111005', 'national_id' => 'SMI0005', 'gender' => 'Female', 'account_number' => 'SMI-ACC-004'])];
// First actually create a member holding that account number
$holder = $memberModel->createWithCompulsoryAccount([
    'member_number' => $memberModel->generateMemberNumber(), 'account_number' => 'SMI-ACC-004',
    'first_name' => 'Holder', 'last_name' => 'Existing', 'gender' => 'Male',
    'phone' => '0700111099', 'national_id' => 'SMIHOLDER', 'station' => null,
    'present_address' => null, 'home_address' => null, 'address' => null,
    'join_date' => date('Y-m-d'), 'status' => 'active', 'status_source' => 'automatic',
], 1);
$dupResult = call($controller, $ref, 'validateRows', [$dupCheckRows]);
check('Account number already assigned to another member -> status=error', $dupResult['rows'][0]['status'] === 'error');
check('Error message names the duplicate account number', str_contains($dupResult['rows'][0]['error'], 'Account number already assigned'), $dupResult['rows'][0]['error']);

// ================================================================
section('F: process()-equivalent commit — account_number, gender, compulsory account, SAVEPOINT isolation');
// ================================================================
$db = Database::getInstance()->getConnection();
$userId = 1;

function importOneRow(MemberModel $mm, PDO $db, array $data, int $userId): array {
    $db->exec('SAVEPOINT import_row');
    try {
        $res = $mm->createWithCompulsoryAccount($data, $userId);
        $db->exec('RELEASE SAVEPOINT import_row');
        return ['ok' => true, 'result' => $res];
    } catch (Throwable $e) {
        $db->exec('ROLLBACK TO SAVEPOINT import_row');
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

$db->beginTransaction();

// F1: account number supplied in file is respected, not overwritten
$countBefore = (int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn();
$r1 = importOneRow($memberModel, $db, [
    'member_number' => $memberModel->generateMemberNumber(), 'account_number' => 'SMI-FILE-001',
    'first_name' => 'File', 'last_name' => 'Given', 'gender' => 'Male',
    'phone' => '0700222001', 'national_id' => 'SMIF001', 'station' => null,
    'present_address' => null, 'home_address' => null, 'address' => null,
    'join_date' => '2020-03-01', 'status' => 'active', 'status_source' => 'automatic',
], $userId);
check('F1: row with file-supplied account number commits successfully', $r1['ok'], $r1['error'] ?? '');
$m1 = $memberModel->find($r1['result']['member_id']);
check('F1: member.account_number is exactly the file value (never overwritten)', $m1['account_number'] === 'SMI-FILE-001', var_export($m1['account_number'] ?? null, true));

// F2: blank account number falls back to generateAccountNumber()
$suggested = $memberModel->generateAccountNumber();
$r2 = importOneRow($memberModel, $db, [
    'member_number' => $memberModel->generateMemberNumber(), 'account_number' => $suggested,
    'first_name' => 'Blank', 'last_name' => 'AutoAcc', 'gender' => 'Female',
    'phone' => '0700222002', 'national_id' => 'SMIF002', 'station' => null,
    'present_address' => null, 'home_address' => null, 'address' => null,
    'join_date' => '2020-03-02', 'status' => 'active', 'status_source' => 'automatic',
], $userId);
check('F2: blank account number -> auto-generated number used', $r2['ok'], $r2['error'] ?? '');
$m2 = $memberModel->find($r2['result']['member_id']);
check('F2: generated account_number was actually assigned', $m2['account_number'] === $suggested, var_export($m2['account_number'] ?? null, true));

// F3: every imported member gets a compulsory savings account (createWithCompulsoryAccount, not bare create())
check('F1 got a compulsory savings account_id', !empty($r1['result']['account_id']));
check('F2 got a compulsory savings account_id', !empty($r2['result']['account_id']));
$acctType1 = $db->query("SELECT account_type FROM member_savings_accounts WHERE id = " . (int)$r1['result']['account_id'])->fetchColumn();
check('F1 savings account is type=compulsory', $acctType1 === 'compulsory', var_export($acctType1, true));

// F4: a row that fails (duplicate account_number) is isolated via SAVEPOINT — batch continues, no orphan left
$r3 = importOneRow($memberModel, $db, [
    'member_number' => $memberModel->generateMemberNumber(), 'account_number' => 'SMI-FILE-001', // duplicate of F1
    'first_name' => 'Dup', 'last_name' => 'AccNum', 'gender' => 'Male',
    'phone' => '0700222003', 'national_id' => 'SMIF003', 'station' => null,
    'present_address' => null, 'home_address' => null, 'address' => null,
    'join_date' => '2020-03-03', 'status' => 'active', 'status_source' => 'automatic',
], $userId);
check('F4: row with duplicate account_number fails cleanly (DB unique constraint or app check)', !$r3['ok']);

// The batch must still be able to continue after a mid-batch failure
$r4 = importOneRow($memberModel, $db, [
    'member_number' => $memberModel->generateMemberNumber(), 'account_number' => 'SMI-FILE-004',
    'first_name' => 'After', 'last_name' => 'Failure', 'gender' => 'Female',
    'phone' => '0700222004', 'national_id' => 'SMIF004', 'station' => null,
    'present_address' => null, 'home_address' => null, 'address' => null,
    'join_date' => '2020-03-04', 'status' => 'active', 'status_source' => 'automatic',
], $userId);
check('F4-continued: a subsequent row still commits fine after the SAVEPOINT rollback', $r4['ok'], $r4['error'] ?? '');

// Confirm no orphaned member row survives from the failed r3 attempt (member row without an id we can find via national_id)
$orphanCheck = $db->query("SELECT COUNT(*) FROM members WHERE national_id = 'SMIF003'")->fetchColumn();
check('F4: no orphaned member row left behind from the failed duplicate-account row', (int)$orphanCheck === 0, "found {$orphanCheck}");

$db->commit();
$countAfter = (int)$db->query("SELECT COUNT(*) FROM members")->fetchColumn();
check('Exactly 3 new members committed this section (F1, F2, F4 — F3 was a duplicate attempt, not a real row)', $countAfter - $countBefore === 3, "before={$countBefore} after={$countAfter}");

// ================================================================
section('G: normalizeDate() — real-world non-ISO date formats');
// ================================================================
function normDate($controller, $ref, $raw) { return call($controller, $ref, 'normalizeDate', [$raw]); }
check('DOB "9/22/2002" (unambiguous, day=22) -> 2002-09-22', normDate($controller, $ref, '9/22/2002') === '2002-09-22', normDate($controller, $ref, '9/22/2002'));
check('DOB "12/25/2001" (unambiguous, day=25) -> 2001-12-25', normDate($controller, $ref, '12/25/2001') === '2001-12-25', normDate($controller, $ref, '12/25/2001'));
check('Join date "13th.06.25" (ordinal+dots, day=13) -> 2025-06-13', normDate($controller, $ref, '13th.06.25') === '2025-06-13', normDate($controller, $ref, '13th.06.25'));
check('Join date "18th/07/25" -> 2025-07-18', normDate($controller, $ref, '18th/07/25') === '2025-07-18', normDate($controller, $ref, '18th/07/25'));
check('Join date "06th.03/2026" -> 2026-03-06', normDate($controller, $ref, '06th.03/2026') === '2026-03-06', normDate($controller, $ref, '06th.03/2026'));
check('Already-ISO "2020-01-15" passes through unchanged', normDate($controller, $ref, '2020-01-15') === '2020-01-15');
check('Genuinely ambiguous "9/1/2026" (both parts <=12) is left UNCONVERTED, never guessed', normDate($controller, $ref, '9/1/2026') === '9/1/2026', normDate($controller, $ref, '9/1/2026'));
check('Blank stays blank', normDate($controller, $ref, '') === '');
check('Garbage "not a date" left as-is for validateRows() to flag', normDate($controller, $ref, 'not a date') === 'not a date');

// ================================================================
section('H: validateRows() — name-both-required, in-batch duplicates, scientific-notation phone');
// ================================================================
$base2 = ['gender' => 'Male', 'national_id' => '', 'phone' => ''];
$rowsH = [
    // H1: last name present, first name blank (the exact shape this
    // user's real file produces if "LAST NAME" and "Name in Full" are
    // both mapped literally without swapping) -- must now be an error,
    // not silently accepted with first_name lost.
    array_merge($base2, ['row_num' => 1, 'first_name' => '', 'last_name' => 'frank', 'phone' => '0700333001', 'national_id' => 'SMIH001']),
    // H2/H3: two rows with the identical phone number (duplicate person / typo)
    array_merge($base2, ['row_num' => 2, 'first_name' => 'Dup', 'last_name' => 'One', 'phone' => '0700333002', 'national_id' => 'SMIH002']),
    array_merge($base2, ['row_num' => 3, 'first_name' => 'Dup', 'last_name' => 'Two', 'phone' => '0700333002', 'national_id' => 'SMIH003']),
    // H4: Excel scientific-notation-mangled phone number
    array_merge($base2, ['row_num' => 4, 'first_name' => 'Sci', 'last_name' => 'Notation', 'phone' => '2.56788E+11', 'national_id' => 'SMIH004']),
    // H5: two rows sharing the same account number
    array_merge($base2, ['row_num' => 5, 'first_name' => 'Acc', 'last_name' => 'One', 'phone' => '0700333005', 'national_id' => 'SMIH005', 'account_number' => 'SMI-DUP-1']),
    array_merge($base2, ['row_num' => 6, 'first_name' => 'Acc', 'last_name' => 'Two', 'phone' => '0700333006', 'national_id' => 'SMIH006', 'account_number' => 'SMI-DUP-1']),
];
$resultH = call($controller, $ref, 'validateRows', [$rowsH]);
$byRowH = [];
foreach ($resultH['rows'] as $r) $byRowH[$r['row_num']] = $r;

check('H1: blank first_name with non-blank last_name -> status=error (no longer silently accepted)', $byRowH[1]['status'] === 'error', json_encode($byRowH[1]));
check('H1: error specifically says "First name missing"', str_contains($byRowH[1]['error'], 'First name missing'), $byRowH[1]['error']);
check('H2: first of a duplicate-phone pair -> flagged as error', $byRowH[2]['status'] === 'error', json_encode($byRowH[2]));
check('H3: second of a duplicate-phone pair -> flagged as error, cross-references row 2', str_contains($byRowH[3]['error'], 'row') && str_contains($byRowH[3]['error'], '2'), $byRowH[3]['error']);
check('H4: scientific-notation phone gets the specific unrecoverable-data explanation', str_contains($byRowH[4]['error'], 'scientific-notation'), $byRowH[4]['error']);
check('H5/H6: duplicate account numbers within the file both flagged', $byRowH[5]['status'] === 'error' && $byRowH[6]['status'] === 'error', json_encode([$byRowH[5], $byRowH[6]]));

// ================================================================
echo "\n=== RESULTS: {$pass} passed, {$fail} failed ===\n";
exit($fail > 0 ? 1 : 0);
