<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/MemberModel.php';

/**
 * MemberImportController — Import members from CSV/Excel
 *
 * Stage 13-F2 (2026-09): this controller previously had no CSRF protection
 * at all (process() had neither a getCsrf()/verifyCsrf() pair nor a POST
 * gate, even though the view already rendered a csrf_token field that was
 * silently ignored), performed zero server-side sanitization/validation of
 * imported fields (raw CSV values went straight into MemberModel::create()),
 * and trusted the client-resubmitted `status === 'ready'` flag as if it were
 * an authoritative validation decision. All three gaps are closed here:
 *   - verifyCsrf() added (byte-identical convention to every other
 *     controller in this codebase, e.g. MemberController, LoanImportController)
 *     and actually enforced in process(), plus an explicit isPost() gate.
 *   - Every imported field is now run through the same sanitize() +
 *     business-validation rules already established by the normal
 *     Add/Edit Member path (MemberController::collectInput()/validate()),
 *     both at preview time (for UX) and again, authoritatively, inside
 *     process() -- the client's copy of `status`/`error` is never trusted;
 *     the server re-derives it from the resubmitted raw field values.
 *   - File size and row-count limits added to preview()/parseCSV() so an
 *     oversized or malformed file is rejected before it is parsed.
 */
class MemberImportController extends Controller
{
    private MemberModel $model;

    /** Stage 13-F2: conservative operational ceiling. This club currently
     *  has on the order of dozens of members; even a full one-time
     *  membership-roll migration is very unlikely to approach four figures.
     *  2,000 rows and 5 MB give roughly two orders of magnitude of headroom
     *  over current/foreseeable usage while still bounding worst-case
     *  memory/time spent parsing an uploaded file server-side. */
    private const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB
    private const MAX_ROWS       = 2000;

    /**
     * System-field -> candidate header synonyms (exact match, tried first)
     * and substring hints (fallback, tried only if no exact match found).
     * Shared by mapColumns() (raw CSV upload path) and suggestMapping()
     * (mapping-confirmation UI path) so both stay in sync with one source
     * of truth. Order matters: more specific fields (e.g. next-of-kin
     * variants, account_number) are matched before generic ones (address,
     * name) so a specific header is never stolen by a broader substring.
     */
    private const FIELD_MATCHERS = [
        'account_number'       => [['account number','account_number','account no','acc no','a/c no','acc number'], ['account']],
        'full_name'            => [['name in full','full name','member name','contact person','names','name'], []],
        'first_name'           => [['first name','first_name','firstname'], []],
        'last_name'            => [['last name','last_name','lastname','surname'], []],
        'gender'               => [['gender','sex'], ['gender']],
        'date_of_birth'        => [['date of birth','date_of_birth','dob'], ['birth','dob']],
        'national_id'          => [['national id (nin)','official personal no(nin)',"official personal no (nin)",'national id','national_id','nin','id number'], ['nin','national']],
        'next_of_kin_full'     => [['next of kin name and address','kin name and address'], []],
        'next_of_kin_name'     => [['next of kin name','next_of_kin_name','next of kin','kin name'], []],
        'next_of_kin_address'  => [['next of kin address','next_of_kin_address','kin address'], []],
        'next_of_kin_phone'    => [["next of kin's contact",'next of kin phone','next_of_kin_phone','kin phone','kin contact'], []],
        'next_of_kin_relation' => [["next of kin's relation to member","next of kin's relation",'next of kin relation','next_of_kin_relation','relation','relationship'], []],
        'phone'                => [['phone','mobile','contact','telephone','mobile no','mobile number'], ['phone','mobile']],
        'email'                => [['email','email address','email adress'], ['email']],
        'station'              => [['station','workplace','organization','school'], ['station']],
        'present_address'      => [['present address','present_address','address','current address'], ['address']],
        'home_address'         => [['home address','home_address','permanent address'], ['home']],
        'join_date'            => [['join date','join_date','date joined','registration date'], ['join']],
    ];

    /** Curated English/Western first-name dictionary for gender inference
     *  (per instruction: "for gender use the english names" -- no Luganda
     *  cultural rules). Used ONLY as a fallback suggestion when a row's
     *  Gender cell is blank; the admin always sees and confirms the value
     *  in the review grid before it is ever written to the database --
     *  never applied silently. */
    private const MALE_FIRST_NAMES = [
        'james','john','robert','michael','william','david','richard','joseph','thomas','charles',
        'christopher','daniel','matthew','anthony','mark','paul','steven','andrew','joshua','kenneth',
        'kevin','brian','george','edward','ronald','timothy','jason','jeffrey','frank','samuel',
        'gary','nicholas','eric','stephen','jonathan','larry','justin','scott','brandon','benjamin',
        'gregory','patrick','jack','dennis','jerry','alexander','tyler','henry','peter','walter',
        'aaron','jose','adam','nathan','douglas','zachary','ivan','simon','isaac','victor',
        'francis','philip','martin','moses','emmanuel','joel','solomon','dennis','gideon','abraham',
        'noah','elias','felix','ronald','vincent','gerald','christian','jesse','austin','ethan',
        'raymond','arthur','albert','roger','keith','lawrence','harold','wayne','ralph','eugene',
        'russell','bruce','howard','carl','willie','alan','juan','billy','bryan','louis',
        'derek','collins','allan','dan','fred','denis','robert','geoffrey','ian','hillary',
    ];
    private const FEMALE_FIRST_NAMES = [
        'mary','patricia','jennifer','linda','elizabeth','barbara','susan','jessica','sarah','karen',
        'nancy','lisa','margaret','betty','sandra','ashley','dorothy','kimberly','emily','donna',
        'michelle','carol','amanda','melissa','deborah','stephanie','rebecca','laura','sharon','cynthia',
        'kathleen','helen','amy','shirley','angela','anna','ruth','brenda','pamela','nicole',
        'katherine','christine','samantha','debra','janet','catherine','frances','christina','joyce','diane',
        'grace','rose','joan','judith','rachel','martha','gloria','teresa','janice','julie',
        'victoria','olivia','sophia','hannah','emma','abigail','esther','sarah','ruth','naomi',
        'faith','patience','joy','peace','irene','florence','agnes','harriet','justine','betty',
        'jane','beatrice','doreen','stella','winnie','edith','judith','molly','claire','sylvia',
        'evelyn','christabel','phoebe','lydia','miriam','deborah','priscilla','vivian','pauline','maureen',
    ];

    public function __construct()
    {
        $this->model = new MemberModel();
    }

    /** Bulk import is now embedded on the Add Member page (members/form.php)
     *  as a second mode alongside the single-member form, so it must be
     *  reachable by whoever can reach that page -- matches
     *  MemberController::requireAddAccess() exactly (previously this was
     *  admin-only, which would have hidden the import tab's own backend
     *  from an office_admin who can see the tab itself). */
    private function requireAdmin(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'office_admin'])) {
            Session::flash('error', 'Access denied.');
            $this->redirect(APP_URL . '/index.php?page=members');
            exit;
        }
    }

    private function getCsrf(): string
    {
        if (!Session::has('csrf_token')) Session::set('csrf_token', bin2hex(random_bytes(32)));
        return Session::get('csrf_token');
    }

    /**
     * Stage 13-F2: identical convention to every other controller's
     * verifyCsrf() (MemberController, LoanImportController,
     * MemberAccountImportController, ExpenseController, etc.) -- compares
     * via hash_equals() and rotates the stored token on every call
     * (pass or fail), single-use per submission. Not a new CSRF system.
     */
    private function verifyCsrf(string $token): bool
    {
        $stored = Session::get('csrf_token', '');
        Session::set('csrf_token', bin2hex(random_bytes(32)));
        return hash_equals($stored, $token);
    }

    // ================================================================
    // IMPORT PAGE
    // ================================================================

    public function index(): void
    {
        $this->requireAdmin();
        $this->render('members/import', [
            'pageTitle'   => 'Import Members — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Members', 'url' => APP_URL . '/index.php?page=members'],
                ['label' => 'Import'],
            ],
            'csrfToken' => $this->getCsrf(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
        ]);
    }

    // ================================================================
    // PREVIEW (AJAX)
    // ================================================================

    public function preview(): void
    {
        $this->requireAdmin();

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file uploaded.'], 400);
            return;
        }

        $file = $_FILES['import_file'];

        // Stage 13-F2: application-level file size ceiling, enforced
        // server-side regardless of what php.ini's own upload_max_filesize
        // happens to allow.
        if ($file['size'] > self::MAX_FILE_BYTES) {
            $this->json(['error' => 'File too large. Maximum size is ' . (self::MAX_FILE_BYTES / 1024 / 1024) . ' MB.'], 400);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv'])) {
            $this->json(['error' => 'Only .csv files are supported. Save your Excel as CSV first.'], 400);
            return;
        }

        // Stage 13-F2: don't trust the extension alone -- confirm the
        // uploaded bytes are actually a plain-text/CSV file (defends
        // against a renamed non-CSV upload; this app never executes or
        // includes uploaded content, but a stricter content check is a
        // cheap, safe improvement with no legitimate-import downside).
        if (is_uploaded_file($file['tmp_name']) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
            if ($finfo) finfo_close($finfo);
            $allowedMime = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'inode/x-empty'];
            if ($mime !== false && !in_array($mime, $allowedMime, true)) {
                $this->json(['error' => 'File does not look like a valid CSV file.'], 400);
                return;
            }
        }

        $rows = $this->parseCSV($file['tmp_name']);
        if ($rows === null) {
            $this->json(['error' => 'File has too many rows. Maximum is ' . self::MAX_ROWS . ' per import.'], 400);
            return;
        }
        if (empty($rows)) {
            $this->json(['error' => 'No data found in file.'], 400);
            return;
        }

        $validated = $this->validateRows($rows);
        $this->json($validated);
    }

    // ================================================================
    // SMART IMPORT: PARSE (AJAX) -- raw headers + suggested mapping,
    // powers the Column Mapping Confirmation step on the Add Member page.
    // Deliberately does NOT map/validate rows yet -- full-name/next-of-
    // kin splitting depends on which header the admin ultimately confirms
    // for which field, which happens client-side after this call.
    // ================================================================

    public function parse(): void
    {
        $this->requireAdmin();

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file uploaded.'], 400);
            return;
        }

        $file = $_FILES['import_file'];

        if ($file['size'] > self::MAX_FILE_BYTES) {
            $this->json(['error' => 'File too large. Maximum size is ' . (self::MAX_FILE_BYTES / 1024 / 1024) . ' MB.'], 400);
            return;
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['csv'])) {
            $this->json(['error' => 'Only .csv files are supported. Save your Excel as CSV first.'], 400);
            return;
        }

        if (is_uploaded_file($file['tmp_name']) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
            if ($finfo) finfo_close($finfo);
            $allowedMime = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'inode/x-empty'];
            if ($mime !== false && !in_array($mime, $allowedMime, true)) {
                $this->json(['error' => 'File does not look like a valid CSV file.'], 400);
                return;
            }
        }

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) { $this->json(['error' => 'Could not read file.'], 400); return; }
        $this->skipBom($handle);

        $rawHeaders = fgetcsv($handle);
        if (!$rawHeaders) { fclose($handle); $this->json(['error' => 'No data found in file.'], 400); return; }
        $headers = array_map(fn($h) => strtolower(trim($h)), $rawHeaders);

        $rows = [];
        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) < 3 || empty(array_filter($data))) continue;
            if (count($rows) >= self::MAX_ROWS) { fclose($handle); $this->json(['error' => 'File has too many rows. Maximum is ' . self::MAX_ROWS . ' per import.'], 400); return; }
            $row = ['row_num' => $rowNum];
            foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
            $rows[] = $row;
        }
        fclose($handle);

        if (empty($rows)) {
            $this->json(['error' => 'No data found in file.'], 400);
            return;
        }

        $this->json([
            'headers'          => array_values($headers),
            'rawHeaders'       => array_values($rawHeaders),
            'suggestedMapping' => $this->suggestMapping($headers),
            'rows'             => $rows,
            'total'            => count($rows),
        ]);
    }

    // ================================================================
    // SMART IMPORT: VALIDATE MAPPED ROWS (AJAX) -- accepts rows already
    // keyed by SYSTEM field names (built client-side from the raw parse()
    // rows + the admin's confirmed mapping + any bulk-default values),
    // runs them through exactly the same coerceRow()+validateRows() rules
    // process() itself uses, and returns the same shape preview() does so
    // the editable review grid can show live Ready/Error status.
    // ================================================================

    public function validateMapped(): void
    {
        $this->requireAdmin();

        $jsonData = $_POST['mapped_data'] ?? '';
        $rows = json_decode($jsonData, true);

        if (empty($rows) || !is_array($rows)) {
            $this->json(['error' => 'No data to validate.'], 400);
            return;
        }
        if (count($rows) > self::MAX_ROWS) {
            $this->json(['error' => 'Too many rows.'], 400);
            return;
        }

        $this->json($this->validateRows($rows));
    }

    // ================================================================
    // PROCESS IMPORT
    // ================================================================

    public function process(): void
    {
        $this->requireAdmin();

        // Smart Import: bulk import is now reachable from two places --
        // the original standalone member-import page, and the new tab
        // embedded on the Add Member page -- so the post-commit redirect
        // target is carried explicitly instead of hardcoded, restricted
        // to a fixed whitelist so it can never become an open redirect.
        $returnTo = in_array($_POST['return_to'] ?? '', ['member-add', 'member-import'], true)
            ? $_POST['return_to'] : 'member-import';
        $returnUrl = APP_URL . '/index.php?page=' . $returnTo;

        // Stage 13-F2: this mutation must never be reachable by GET, and
        // must carry a valid, single-use CSRF token -- the view has
        // rendered one all along (members/import.php:74); this controller
        // simply never checked it.
        if (!$this->isPost()) {
            $this->redirect($returnUrl);
            return;
        }

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect($returnUrl);
            return;
        }

        $jsonData = $_POST['import_data'] ?? '';
        $rows = json_decode($jsonData, true);

        if (empty($rows) || !is_array($rows)) {
            Session::flash('error', 'No valid data to import.');
            $this->redirect($returnUrl);
            return;
        }

        if (count($rows) > self::MAX_ROWS) {
            Session::flash('error', 'Too many rows submitted.');
            $this->redirect($returnUrl);
            return;
        }

        // Stage 13-F2: the client's `status`/`error` fields on each
        // resubmitted row are advisory only -- a browser (or a replayed/
        // edited request) could flip a row that the preview marked
        // invalid to "ready", or resubmit a row unchanged after another
        // admin has since registered the same phone/NIN. The server
        // re-derives readiness from scratch, from the raw resubmitted
        // field values, immediately before committing -- ignoring
        // whatever `status`/`error` the client sent.
        $rows = array_map(function (array $row) {
            unset($row['status'], $row['error']);
            return $row;
        }, $rows);
        $revalidated = $this->validateRows($rows);
        $rows = $revalidated['rows'];

        $db = Database::getInstance()->getConnection();
        $imported = 0; $skipped = 0; $errors = 0;
        $userId = (int)Session::get('user_id');
        $log = [];

        try {
            $db->beginTransaction();

            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'ready') {
                    $skipped++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'skipped', 'message' => $row['error'] ?? 'Not ready'];
                    continue;
                }

                $memberNumber = $this->model->generateMemberNumber();

                // Stage 13-F2: sanitize() is applied exactly once, right
                // here at the point of writing to the database -- the
                // same single-application convention MemberController
                // uses for the normal Add/Edit path. It is NOT applied
                // during preview/validateRows() (which only validates raw
                // trimmed text), so a value can never be run through
                // htmlspecialchars() twice across the preview -> resubmit
                // -> commit round trip.
                $clean = $this->sanitizeRow($row);

                // Smart Import: account_number now comes from the file
                // when the admin's sheet has one (validated for format +
                // uniqueness in validateRows()); only generated here when
                // the cell was left blank -- never overwrites a supplied
                // value. Gender is no longer defaulted anywhere -- a
                // 'ready' row is only reachable with an explicit,
                // validated Male/Female/Other value already confirmed by
                // the admin in the review grid.
                $data = [
                    'member_number'       => $memberNumber,
                    'account_number'      => $clean['account_number'] ?: $this->model->generateAccountNumber(),
                    'first_name'          => $clean['first_name'],
                    'last_name'           => $clean['last_name'],
                    'gender'              => $clean['gender'],
                    'date_of_birth'       => $clean['date_of_birth'] ?: null,
                    'phone'               => $clean['phone'],
                    'email'               => $clean['email'] ?: null,
                    'national_id'         => $clean['national_id'],
                    'station'             => $clean['station'] ?: null,
                    'present_address'     => $clean['present_address'] ?: null,
                    'home_address'        => $clean['home_address'] ?: null,
                    'address'             => $clean['present_address'] ?: null,
                    'next_of_kin_name'    => $clean['next_of_kin_name'] ?: null,
                    'next_of_kin_address' => $clean['next_of_kin_address'] ?: null,
                    'next_of_kin_phone'   => $clean['next_of_kin_phone'] ?: null,
                    'next_of_kin_relation'=> $clean['next_of_kin_relation'] ?: null,
                    // Last-resort safety net only -- the mapping-
                    // confirmation step on the Add Member page lets the
                    // admin set one bulk Join Date applied to every blank
                    // row *before* this point, visibly, so this silently
                    // firing is not the expected path for a normal import.
                    'join_date'           => $clean['join_date'] ?: date('Y-m-d'),
                    'status'              => 'active',
                    'status_source'       => 'automatic', // Stage 6-C: bulk-imported members start system-managed, same as the standard Add form
                    'created_by'          => $userId,
                ];

                // Smart Import: bulk-imported members now get the same
                // compulsory savings account the single Add Member form
                // has always created (createWithCompulsoryAccount()) --
                // previously this used the bare create(), silently
                // leaving every imported member without one. A per-row
                // SAVEPOINT isolates one row's failure (e.g. the
                // application-level "one compulsory account per member"
                // guard, or a last-instant duplicate) from the rest of
                // the batch, since createWithCompulsoryAccount() detects
                // it's already inside this method's open transaction and
                // therefore won't roll back on its own.
                $db->exec('SAVEPOINT import_row');
                try {
                    $result = $this->model->createWithCompulsoryAccount($data, $userId);
                    $db->exec('RELEASE SAVEPOINT import_row');
                    $imported++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'imported', 'message' => "{$data['first_name']} {$data['last_name']} → {$memberNumber} (Savings Account {$result['account_number']})"];
                } catch (Throwable $e) {
                    $db->exec('ROLLBACK TO SAVEPOINT import_row');
                    $errors++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'error', 'message' => "Failed: {$data['first_name']} {$data['last_name']} — {$e->getMessage()}"];
                }
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            Session::flash('error', 'Import failed: ' . $e->getMessage());
            $this->redirect($returnUrl);
            return;
        }

        $this->model->log($userId, 'members_imported', "Imported {$imported} members. Skipped: {$skipped}");

        Session::set('member_import_result', [
            'imported' => $imported, 'skipped' => $skipped,
            'errors' => $errors, 'total' => count($rows), 'log' => $log,
        ]);

        Session::flash('success', "Import completed: {$imported} members imported, {$skipped} skipped.");
        $this->redirect($returnUrl);
    }

    // ================================================================
    // DOWNLOAD TEMPLATE
    // ================================================================

    /**
     * Template download — serves the CSV template file.
     * Auth check requires DB; if DB is unavailable the static file
     * at public/downloads/member_import_template.csv can be used directly.
     */
    public function template(): void
    {
        $this->requireAdmin();
        $this->sendTemplateCsv();
    }

    /**
     * Direct template download — no auth, no DB.
     * Safe because it only outputs a static CSV template with no data.
     */
    public function templateDirect(): void
    {
        $this->sendTemplateCsv();
    }

    private function sendTemplateCsv(): void
    {
        $headers = [
            'First Name', 'Last Name', 'Gender', 'Date of Birth',
            'Phone', 'Email', 'National ID (NIN)', 'Station',
            'Present Address', 'Home Address',
            'Next of Kin Name', 'Next of Kin Address', 'Next of Kin Phone', 'Next of Kin Relation',
            'Account Number', 'Join Date',
        ];
        $sample = [
            'David', 'Nsubuga', 'Male', '1996-10-12',
            '0751407879', 'nsubugadavids9@gmail.com', 'CM9503210J6PUA', 'Pharmacy Kalagi',
            'Kayunga', 'Kayunga',
            'Nabatanzi Daphine', 'Kayunga', '0756189331', 'Wife',
            '', '2026-05-14',
        ];

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="member_import_template.csv"');
        header('Cache-Control: no-cache, no-store');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');
        // UTF-8 BOM so Excel opens it correctly without garbled characters
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        fputcsv($out, $sample);
        fclose($out);
        exit;
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Stage 13-F2: returns null (distinct from an empty array) when the
     * file exceeds MAX_ROWS, so callers can tell "too many rows" apart
     * from "legitimately empty file".
     */
    /**
     * Excel's "CSV UTF-8" export (the format this app's own template and
     * instructions tell admins to use) always prepends a UTF-8 BOM
     * (EF BB BF). fgetcsv() only recognizes a field as CSV-quoted when
     * the enclosure character is the literal first byte of that field --
     * a leading BOM defeats that check, so the header row either comes
     * back with literal stray quote characters baked into it (if Excel
     * quoted that header) or with invisible BOM bytes silently prefixed
     * onto the first column's name (if it didn't) -- either way the
     * first header never matches anything in FIELD_MATCHERS, and every
     * row then fails validation with the first mapped field missing.
     * Consumes exactly those 3 bytes when present, otherwise rewinds so
     * nothing is lost from a file that has no BOM.
     */
    private function skipBom($handle): void
    {
        $bom = fread($handle, 3);
        if ($bom !== "\xEF\xBB\xBF") rewind($handle);
    }

    private function parseCSV(string $filepath): ?array
    {
        $rows = [];
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];
        $this->skipBom($handle);

        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }
        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) < 3 || empty(array_filter($data))) continue;

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                return null;
            }

            $row = [];
            foreach ($headers as $i => $h) { $row[$h] = trim($data[$i] ?? ''); }
            $row['row_num'] = $rowNum;
            $rows[] = $this->mapColumns($row);
        }
        fclose($handle);
        return $rows;
    }

    /**
     * Substring/keyword-based fuzzy header matcher (replaces the old
     * brittle exact-string-only lookups). Tries every exact synonym
     * first; only falls back to a substring scan across the row's actual
     * headers if no exact synonym is present. $exclude lets a caller keep
     * a broad substring (e.g. "name") from grabbing a header that belongs
     * to a more specific field (e.g. "next of kin name").
     */
    private function findColumn(array $row, array $exact, array $substrings, array $exclude = []): string
    {
        foreach ($exact as $key) {
            if (isset($row[$key]) && $row[$key] !== '') return $row[$key];
        }
        foreach ($row as $header => $value) {
            if ($header === 'row_num') continue;
            foreach ($exclude as $ex) { if (str_contains($header, $ex)) continue 2; }
            foreach ($substrings as $sub) {
                if (str_contains($header, $sub)) return $value;
            }
        }
        return '';
    }

    private function mapColumns(array $row): array
    {
        $firstName = $this->findColumn($row, self::FIELD_MATCHERS['first_name'][0], []);
        $lastName  = $this->findColumn($row, self::FIELD_MATCHERS['last_name'][0], []);
        $fullName  = '';

        if ($firstName === '' && $lastName === '') {
            $fullName = $this->findColumn($row, self::FIELD_MATCHERS['full_name'][0], self::FIELD_MATCHERS['full_name'][1], ['kin']);
        }

        $kinFull = $this->findColumn($row, self::FIELD_MATCHERS['next_of_kin_full'][0], []);

        return [
            'row_num'              => $row['row_num'] ?? 0,
            'first_name'           => $firstName,
            'last_name'            => $lastName,
            'full_name'            => $fullName,
            'account_number'       => $this->findColumn($row, self::FIELD_MATCHERS['account_number'][0], self::FIELD_MATCHERS['account_number'][1]),
            'gender'               => $this->findColumn($row, self::FIELD_MATCHERS['gender'][0], []),
            'date_of_birth'        => $this->findColumn($row, self::FIELD_MATCHERS['date_of_birth'][0], []),
            'phone'                => $this->findColumn($row, self::FIELD_MATCHERS['phone'][0], self::FIELD_MATCHERS['phone'][1], ['kin']),
            'email'                => $this->findColumn($row, self::FIELD_MATCHERS['email'][0], []),
            'national_id'          => $this->findColumn($row, self::FIELD_MATCHERS['national_id'][0], []),
            'station'              => $this->findColumn($row, self::FIELD_MATCHERS['station'][0], self::FIELD_MATCHERS['station'][1]),
            'present_address'      => $this->findColumn($row, self::FIELD_MATCHERS['present_address'][0], self::FIELD_MATCHERS['present_address'][1], ['kin','home']),
            'home_address'         => $this->findColumn($row, self::FIELD_MATCHERS['home_address'][0], self::FIELD_MATCHERS['home_address'][1]),
            'next_of_kin_name'     => $kinFull !== '' ? $kinFull : $this->findColumn($row, self::FIELD_MATCHERS['next_of_kin_name'][0], []),
            'next_of_kin_address'  => $kinFull !== '' ? '' : $this->findColumn($row, self::FIELD_MATCHERS['next_of_kin_address'][0], []),
            'next_of_kin_phone'    => $this->findColumn($row, self::FIELD_MATCHERS['next_of_kin_phone'][0], []),
            'next_of_kin_relation' => $this->findColumn($row, self::FIELD_MATCHERS['next_of_kin_relation'][0], []),
            'join_date'            => $this->findColumn($row, self::FIELD_MATCHERS['join_date'][0], []),
        ];
    }

    /**
     * Given the raw headers present in an uploaded file, suggest which
     * header best fills each system field -- powers the Column Mapping
     * Confirmation step's pre-filled dropdowns. Returns field => header
     * (or '' when nothing matched). A header already claimed by an
     * earlier (more specific) field is not offered again to a later,
     * broader field.
     */
    private function suggestMapping(array $headers): array
    {
        $available = array_values(array_filter($headers, fn($h) => $h !== 'row_num'));
        $used = [];
        $suggestion = [];

        foreach (self::FIELD_MATCHERS as $field => [$exact, $substrings]) {
            $exclude = in_array($field, ['present_address'], true) ? ['kin', 'home'] : [];
            if (in_array($field, ['phone'], true)) $exclude[] = 'kin';

            $match = '';
            foreach ($exact as $key) {
                if (in_array($key, $available, true) && !in_array($key, $used, true)) { $match = $key; break; }
            }
            if ($match === '') {
                foreach ($available as $h) {
                    if (in_array($h, $used, true)) continue;
                    $skip = false;
                    foreach ($exclude as $ex) { if (str_contains($h, $ex)) { $skip = true; break; } }
                    if ($skip) continue;
                    foreach ($substrings as $sub) {
                        if (str_contains($h, $sub)) { $match = $h; break 2; }
                    }
                }
            }
            if ($match !== '') { $suggestion[$field] = $match; $used[] = $match; }
        }

        return $suggestion;
    }

    /**
     * "for gender use the english names" -- match only against a curated
     * English/Western first-name dictionary, first token only, case-
     * insensitive. Returns null (never a guess presented as fact) when
     * the name isn't recognized, so the UI can show "Uncertain -- please
     * select" instead of a fabricated value.
     */
    private function guessGender(string $firstName): ?string
    {
        $token = strtolower(trim(explode(' ', trim($firstName))[0] ?? ''));
        if ($token === '') return null;
        if (in_array($token, self::MALE_FIRST_NAMES, true)) return 'Male';
        if (in_array($token, self::FEMALE_FIRST_NAMES, true)) return 'Female';
        return null;
    }

    /**
     * Real-world spreadsheets almost never use ISO Y-m-d for dates.
     * Normalizes the common cases we actually see -- Excel serial-number
     * dates, "13th.06.25"/"18th/07/25"-style ordinal-suffixed dates, and
     * plain numeric d/m/Y or m/d/Y -- to Y-m-d so validateRows()'s
     * existing Y-m-d check (unchanged) can pass them.
     *
     * Deliberately conservative: when a date's two numeric parts could
     * each plausibly be the day or the month (e.g. "9/1/2026" -- Sept 1
     * or Jan 9?), it is left UNCONVERTED rather than guessed, so
     * validateRows() flags it as invalid and the admin fixes it by hand
     * in the review grid. A wrong guess on a Date of Birth is worse than
     * an extra manual correction -- this never silently picks one.
     */
    private function normalizeDate(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '' || DateTime::createFromFormat('Y-m-d', $raw) !== false) return $raw;

        // Excel serial date leaking through as a bare number (epoch 1899-12-30)
        if (preg_match('/^\d{4,6}$/', $raw)) {
            $dt = (new DateTime('1899-12-30'))->modify('+' . (int)$raw . ' days');
            return $dt->format('Y-m-d');
        }

        // An ordinal suffix (1st/2nd/3rd/4th...) is conventionally only
        // ever written on a day-of-month, never a month number -- track
        // which of the two leading numeric parts carries one BEFORE
        // stripping it, so e.g. "06th.03/2026" (both parts individually
        // <=12, ambiguous by magnitude alone) can still be safely
        // resolved as day=06, month=03 rather than left unconverted.
        $ordinalOnFirst  = (bool)preg_match('/^\s*\d{1,2}(st|nd|rd|th)\b/i', $raw);
        $ordinalOnSecond = (bool)preg_match('#^\s*\d{1,2}\s*[./\-]\s*\d{1,2}(st|nd|rd|th)\b#i', $raw);

        $clean = preg_replace('/(\d)(st|nd|rd|th)/i', '$1', $raw);
        $clean = str_replace(['.', '-', ' '], '/', trim($clean));
        $clean = trim(preg_replace('#/+#', '/', $clean), '/');

        if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', $clean, $m)) {
            return $raw; // not a recognized shape -- leave for validateRows() to flag
        }
        [, $a, $b, $year] = $m;
        $a = (int)$a; $b = (int)$b;
        $year = strlen($year) === 2 ? (int)(((int)$year <= 30 ? '20' : '19') . $year) : (int)$year;

        $aIsMonth = $a >= 1 && $a <= 12;
        $bIsMonth = $b >= 1 && $b <= 12;
        if ($aIsMonth && $bIsMonth) {
            if ($ordinalOnFirst)       { $day = $a; $month = $b; }
            elseif ($ordinalOnSecond)  { $day = $b; $month = $a; }
            else                       { return $raw; } // truly ambiguous -- never guessed
        } elseif ($aIsMonth && !$bIsMonth) { $month = $a; $day = $b; }
        elseif ($bIsMonth && !$aIsMonth)   { $month = $b; $day = $a; }
        else                                { return $raw; } // invalid

        $dt = DateTime::createFromFormat('!Y-n-j', "{$year}-{$month}-{$day}");
        if (!$dt || (int)$dt->format('j') !== $day) return $raw; // e.g. day 31 in a 30-day month
        return $dt->format('Y-m-d');
    }

    /**
     * Stage 13-F2: trims/coerces a row's fields WITHOUT sanitizing them.
     * Used to normalize both freshly-mapped CSV rows and client-
     * resubmitted JSON rows into the same flat shape before validation.
     * Deliberately does not call sanitize() here -- see sanitizeRow()
     * below for why that must stay a separate, single-use step.
     */
    private function coerceRow(array $row): array
    {
        $t = fn($v) => trim((string)($v ?? ''));

        $firstName = $t($row['first_name'] ?? '');
        $lastName  = $t($row['last_name'] ?? '');
        // Splits a combined "Name in Full"-style column when the caller
        // (mapColumns(), or the client-side mapping-confirmation step)
        // couldn't already resolve separate first/last names. Applied
        // here so both the raw-CSV path and the pre-mapped-JSON path
        // (member-import-validate) get the same split logic.
        if ($firstName === '' && $lastName === '') {
            $full = $t($row['full_name'] ?? '');
            if ($full !== '') {
                $parts = explode(' ', $full, 2);
                $firstName = $parts[0] ?? '';
                $lastName  = trim($parts[1] ?? '');
            }
        }

        $kinName    = $t($row['next_of_kin_name'] ?? '');
        $kinAddress = $t($row['next_of_kin_address'] ?? '');
        if ($kinName === '' && $kinAddress === '') {
            $kinFull = $t($row['next_of_kin_full'] ?? '');
            if ($kinFull !== '') $kinName = $kinFull; // no reliable auto-split; admin can move the address part manually in the review grid
        }

        return [
            'row_num'              => (int)($row['row_num'] ?? 0),
            'first_name'           => $firstName,
            'last_name'            => $lastName,
            'account_number'       => $t($row['account_number'] ?? ''),
            'gender'               => $t($row['gender'] ?? ''),
            'date_of_birth'        => $this->normalizeDate($t($row['date_of_birth'] ?? '')),
            'phone'                => $t($row['phone'] ?? ''),
            'email'                => strtolower($t($row['email'] ?? '')),
            'national_id'          => $t($row['national_id'] ?? ''),
            'station'              => $t($row['station'] ?? ''),
            'present_address'      => $t($row['present_address'] ?? ''),
            'home_address'         => $t($row['home_address'] ?? ''),
            'next_of_kin_name'     => $kinName,
            'next_of_kin_address'  => $kinAddress,
            'next_of_kin_phone'    => $t($row['next_of_kin_phone'] ?? ''),
            'next_of_kin_relation' => $t($row['next_of_kin_relation'] ?? ''),
            'join_date'            => $this->normalizeDate($t($row['join_date'] ?? '')),
        ];
    }

    /**
     * Stage 13-F2: the ONLY place sanitize() (htmlspecialchars(strip_tags
     * (trim()))) is applied to import data, and it is applied exactly
     * once, only in process(), only to rows that have just been
     * authoritatively re-validated and are about to be written to the
     * database -- mirroring the normal Add/Edit Member path's single
     * sanitize-on-write convention exactly. It is deliberately NOT used
     * inside validateRows()/coerceRow(), because validateRows() output is
     * echoed back to the browser (preview) and later resubmitted by the
     * browser (process); sanitizing at either of those points would mean
     * a value could be run through htmlspecialchars() twice by the time
     * it reaches the database, corrupting any legitimate name/address
     * containing '&', '<', '>', '"' or "'".
     */
    private function sanitizeRow(array $row): array
    {
        $s = fn($v) => $this->sanitize((string)($v ?? ''));
        $clean = $row;
        foreach (['first_name','last_name','account_number','gender','date_of_birth','phone','email',
                  'national_id','station','present_address','home_address',
                  'next_of_kin_name','next_of_kin_address','next_of_kin_phone','next_of_kin_relation','join_date'] as $f) {
            $clean[$f] = $s($row[$f] ?? '');
        }
        $clean['email'] = strtolower($clean['email']);
        // Uppercased for display uniformity across the members list --
        // matches MemberController::collectInput()'s single Add/Edit
        // Member path, since bulk-imported spreadsheets mix Title Case
        // and ALL CAPS row by row.
        $clean['first_name'] = strtoupper($clean['first_name']);
        $clean['last_name']  = strtoupper($clean['last_name']);
        return $clean;
    }

    /**
     * Stage 13-F2: rewritten to (a) reuse the exact same business-
     * validation rules as MemberController::validate() -- required/
     * min-length on names, gender enum, valid past date of birth, phone
     * format + uniqueness, valid email + uniqueness, national ID min
     * length + uniqueness, valid join date -- and (b) enforce server-side
     * maximum lengths matching the members table's actual column sizes,
     * checked against what the value's length WILL BE once sanitized (so
     * a value that would overflow its column after htmlspecialchars
     * expansion is caught here, not at the database layer). Field values
     * are validated in their raw (trimmed, unescaped) form and returned
     * unescaped -- see sanitizeRow() above for why escaping happens
     * exactly once, later, only in process().
     */
    private function validateRows(array $rows): array
    {
        $validated = []; $ready = 0; $errorCount = 0; $skipCount = 0;

        // Same-file duplicate detection: a real spreadsheet can contain the
        // same person twice, or two different people accidentally sharing
        // a mistyped phone/account number. The DB-uniqueness checks below
        // only ever see committed data, not sibling rows in this same
        // batch -- without this pre-pass, two duplicate rows in one file
        // would both pass individually and only collide at commit time
        // (caught, but as a late per-row failure, not a visible error in
        // the review grid beforehand). NIN is deliberately not tracked
        // here -- see the national_id policy note below.
        $phoneRows = []; $acctRows = [];
        foreach ($rows as $raw) {
            $c = $this->coerceRow($raw);
            if ($c['phone'] !== '')          $phoneRows[$c['phone']][]         = $c['row_num'];
            if ($c['account_number'] !== '') $acctRows[$c['account_number']][] = $c['row_num'];
        }

        foreach ($rows as $raw) {
            $row = $this->coerceRow($raw);
            $lenCheck = $this->sanitizeRow($row); // length checks only; never persisted/returned
            $errors = [];

            if (empty($row['first_name']) || empty($row['last_name'])) {
                // Stage: Smart Import -- previously only flagged when BOTH
                // were blank, so a row with e.g. a mapped Last Name column
                // but no First Name column would pass silently, losing the
                // other name entirely with no error raised. Now requires
                // both independently, matching the single Add Member
                // form's own required fields.
                $errors[] = (empty($row['first_name']) && empty($row['last_name']))
                    ? 'Name missing'
                    : (empty($row['first_name']) ? 'First name missing' : 'Last name missing');
                if ($row['first_name'] !== '' && strlen($lenCheck['first_name']) > 80) $errors[] = 'First name too long';
                if ($row['last_name']  !== '' && strlen($lenCheck['last_name'])  > 80) $errors[] = 'Last name too long';
            } else {
                if (strlen($row['first_name']) < 2) $errors[] = 'First name too short';
                if (strlen($row['last_name'])  < 2) $errors[] = 'Last name too short';
                if (strlen($lenCheck['first_name']) > 80) $errors[] = 'First name too long';
                if (strlen($lenCheck['last_name'])  > 80) $errors[] = 'Last name too long';
            }

            $row['suggested_gender'] = '';
            if ($row['gender'] === '') {
                $errors[] = 'Gender missing';
                // Stage: Smart Import -- a blank Gender cell is never
                // silently defaulted (that used to happen in three places
                // here). We only offer an English-name-dictionary guess
                // for the admin to see and confirm/override in the
                // review grid; the row stays 'error' until they do.
                $guess = $this->guessGender($row['first_name']);
                if ($guess !== null) $row['suggested_gender'] = $guess;
            } elseif (!in_array($row['gender'], ['Male', 'Female', 'Other'], true)) {
                $errors[] = 'Invalid gender';
            }

            if ($row['date_of_birth'] !== '') {
                $dob = DateTime::createFromFormat('Y-m-d', $row['date_of_birth']);
                if (!$dob || $dob >= new DateTime()) $errors[] = 'Invalid date of birth';
            }

            if (empty($row['phone'])) {
                $errors[] = 'Phone missing';
            } elseif (preg_match('/^\d(\.\d+)?E\+\d+$/i', $row['phone'])) {
                // Excel silently reformats a long digit string as a number
                // and exports it in scientific notation, which is a
                // one-way, unrecoverable loss of the trailing digits --
                // there is nothing to parse here. Named explicitly so the
                // admin knows to fix the SOURCE spreadsheet (format the
                // column as Text, re-enter the number), not just retype
                // in this grid from a value that no longer has the real
                // digits.
                $errors[] = 'Phone lost to Excel scientific-notation export -- re-enter from the original source (format that column as Text in Excel first)';
            } elseif (!preg_match('/^[+0-9][\d\s\-().]{6,19}$/', $row['phone'])) {
                $errors[] = 'Invalid phone format';
            } elseif (strlen($lenCheck['phone']) > 20) {
                $errors[] = 'Phone too long';
            } elseif ($this->model->phoneExists($row['phone'])) {
                $errors[] = 'Phone already registered';
            } elseif (count($phoneRows[$row['phone']] ?? []) > 1) {
                $errors[] = 'Duplicate phone within this file (rows ' . implode(', ', $phoneRows[$row['phone']]) . ')';
            }

            if ($row['email'] !== '') {
                if (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email format';
                } elseif (strlen($lenCheck['email']) > 191) {
                    $errors[] = 'Email too long';
                } elseif ($this->model->emailExists($row['email'])) {
                    $errors[] = 'Email already registered';
                }
            }

            // Policy decision (explicit, informed choice -- the alternative
            // was a second savings account under one member instead of two
            // member records): National ID is no longer required to be
            // unique across members, here or on the single Add/Edit Member
            // form (which never enforced it either). One real person can
            // now hold more than one member record under the same NIN
            // (e.g. a personal registration and a separate business
            // registration). This intentionally removes the system's only
            // automatic protection against the same person being
            // registered twice by mistake -- national_id has no database-
            // level uniqueness constraint in production, so this is a
            // pure application-layer relaxation, not a schema change.
            if (empty($row['national_id'])) {
                $errors[] = 'NIN missing';
            } elseif (strlen($row['national_id']) < 5) {
                $errors[] = 'NIN too short';
            } elseif (strlen($lenCheck['national_id']) > 50) {
                $errors[] = 'NIN too long';
            }

            if ($row['join_date'] !== '' && !DateTime::createFromFormat('Y-m-d', $row['join_date'])) {
                $errors[] = 'Invalid join date';
            }

            if ($row['account_number'] !== '') {
                if (!preg_match('/^[A-Za-z0-9\-\/]+$/', $row['account_number'])) {
                    $errors[] = 'Invalid account number format';
                } elseif (strlen($lenCheck['account_number']) > 30) {
                    $errors[] = 'Account number too long';
                } elseif ($this->model->accountNumberExists($row['account_number'])) {
                    $errors[] = 'Account number already assigned to another member';
                } elseif (count($acctRows[$row['account_number']] ?? []) > 1) {
                    $errors[] = 'Duplicate account number within this file (rows ' . implode(', ', $acctRows[$row['account_number']]) . ')';
                }
            }

            if (strlen($lenCheck['station']) > 200) $errors[] = 'Station too long';
            if (strlen($lenCheck['next_of_kin_name']) > 150) $errors[] = 'Next of kin name too long';
            if (strlen($lenCheck['next_of_kin_address']) > 500) $errors[] = 'Next of kin address too long';
            if (strlen($lenCheck['next_of_kin_phone']) > 20) $errors[] = 'Next of kin phone too long';
            if (strlen($lenCheck['next_of_kin_relation']) > 100) $errors[] = 'Next of kin relation too long';

            if (empty($errors)) { $row['status'] = 'ready'; $row['error'] = ''; $ready++; }
            else { $row['status'] = 'error'; $row['error'] = implode('; ', $errors); $errorCount++; }

            $validated[] = $row;
        }

        return ['rows' => $validated, 'total' => count($rows), 'ready' => $ready, 'errors' => $errorCount, 'skipped' => $skipCount];
    }
}
