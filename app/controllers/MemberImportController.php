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

    public function __construct()
    {
        $this->model = new MemberModel();
    }

    private function requireAdmin(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
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
    // PROCESS IMPORT
    // ================================================================

    public function process(): void
    {
        $this->requireAdmin();

        // Stage 13-F2: this mutation must never be reachable by GET, and
        // must carry a valid, single-use CSRF token -- the view has
        // rendered one all along (members/import.php:74); this controller
        // simply never checked it.
        if (!$this->isPost()) {
            $this->redirect(APP_URL . '/index.php?page=member-import');
            return;
        }

        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=member-import');
            return;
        }

        $jsonData = $_POST['import_data'] ?? '';
        $rows = json_decode($jsonData, true);

        if (empty($rows) || !is_array($rows)) {
            Session::flash('error', 'No valid data to import.');
            $this->redirect(APP_URL . '/index.php?page=member-import');
            return;
        }

        if (count($rows) > self::MAX_ROWS) {
            Session::flash('error', 'Too many rows submitted.');
            $this->redirect(APP_URL . '/index.php?page=member-import');
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

                $data = [
                    'member_number'       => $memberNumber,
                    'first_name'          => $clean['first_name'],
                    'last_name'           => $clean['last_name'],
                    'gender'              => $clean['gender'] ?: 'Male',
                    'date_of_birth'       => $clean['date_of_birth'] ?: null,
                    'phone'               => $clean['phone'],
                    'email'               => $clean['email'] ?: null,
                    'national_id'         => $clean['national_id'],
                    'station'             => $clean['station'] ?: null,
                    'present_address'     => $clean['present_address'] ?: null,
                    'home_address'        => $clean['home_address'] ?: null,
                    'address'             => $clean['present_address'] ?: null,
                    'next_of_kin_name'    => $clean['next_of_kin_name'] ?: null,
                    'next_of_kin_phone'   => $clean['next_of_kin_phone'] ?: null,
                    'next_of_kin_relation'=> $clean['next_of_kin_relation'] ?: null,
                    'join_date'           => $clean['join_date'] ?: date('Y-m-d'),
                    'status'              => 'active',
                    'created_by'          => $userId,
                ];

                $newId = $this->model->create($data);
                if ($newId) {
                    $imported++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'imported', 'message' => "{$data['first_name']} {$data['last_name']} → {$memberNumber}"];
                } else {
                    $errors++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'error', 'message' => "Failed: {$data['first_name']} {$data['last_name']}"];
                }
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            Session::flash('error', 'Import failed: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=member-import');
            return;
        }

        $this->model->log($userId, 'members_imported', "Imported {$imported} members. Skipped: {$skipped}");

        Session::set('member_import_result', [
            'imported' => $imported, 'skipped' => $skipped,
            'errors' => $errors, 'total' => count($rows), 'log' => $log,
        ]);

        Session::flash('success', "Import completed: {$imported} members imported, {$skipped} skipped.");
        $this->redirect(APP_URL . '/index.php?page=member-import');
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
            'Next of Kin Name', 'Next of Kin Phone', 'Next of Kin Relation',
            'Join Date',
        ];
        $sample = [
            'David', 'Nsubuga', 'Male', '1996-10-12',
            '0751407879', 'nsubugadavids9@gmail.com', 'CM9503210J6PUA', 'Pharmacy Kalagi',
            'Kayunga', 'Kayunga',
            'Nabatanzi Daphine', '0756189331', 'Wife',
            '2026-05-14',
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
    private function parseCSV(string $filepath): ?array
    {
        $rows = [];
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];

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

    private function mapColumns(array $row): array
    {
        // Parse name if combined in one field
        $firstName = $row['first name'] ?? $row['first_name'] ?? $row['firstname'] ?? '';
        $lastName  = $row['last name'] ?? $row['last_name'] ?? $row['lastname'] ?? $row['surname'] ?? '';

        // If only a "name" or "full name" field exists
        if (empty($firstName) && empty($lastName)) {
            $fullName = $row['name'] ?? $row['full name'] ?? $row['member name'] ?? $row['contact person'] ?? '';
            $parts = explode(' ', trim($fullName), 2);
            $firstName = $parts[0] ?? '';
            $lastName  = $parts[1] ?? '';
        }

        return [
            'row_num'              => $row['row_num'] ?? 0,
            'first_name'           => $firstName,
            'last_name'            => $lastName,
            'gender'               => $row['gender'] ?? $row['sex'] ?? 'Male',
            'date_of_birth'        => $row['date of birth'] ?? $row['date_of_birth'] ?? $row['dob'] ?? '',
            'phone'                => $row['phone'] ?? $row['mobile'] ?? $row['contact'] ?? $row['telephone'] ?? $row['mobile no'] ?? '',
            'email'                => $row['email'] ?? $row['email address'] ?? '',
            'national_id'          => $row['national id (nin)'] ?? $row['national id'] ?? $row['national_id'] ?? $row['nin'] ?? $row['id number'] ?? '',
            'station'              => $row['station'] ?? $row['workplace'] ?? $row['organization'] ?? $row['school'] ?? '',
            'present_address'      => $row['present address'] ?? $row['present_address'] ?? $row['address'] ?? $row['current address'] ?? '',
            'home_address'         => $row['home address'] ?? $row['home_address'] ?? $row['permanent address'] ?? '',
            'next_of_kin_name'     => $row['next of kin name'] ?? $row['next_of_kin_name'] ?? $row['next of kin'] ?? $row['kin name'] ?? '',
            'next_of_kin_phone'    => $row['next of kin phone'] ?? $row['next_of_kin_phone'] ?? $row['kin phone'] ?? $row['kin contact'] ?? '',
            'next_of_kin_relation' => $row['next of kin relation'] ?? $row['next_of_kin_relation'] ?? $row['relation'] ?? $row['relationship'] ?? '',
            'join_date'            => $row['join date'] ?? $row['join_date'] ?? $row['date joined'] ?? $row['registration date'] ?? '',
        ];
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

        return [
            'row_num'              => (int)($row['row_num'] ?? 0),
            'first_name'           => $t($row['first_name'] ?? ''),
            'last_name'            => $t($row['last_name'] ?? ''),
            'gender'               => $t($row['gender'] ?? '') ?: 'Male',
            'date_of_birth'        => $t($row['date_of_birth'] ?? ''),
            'phone'                => $t($row['phone'] ?? ''),
            'email'                => strtolower($t($row['email'] ?? '')),
            'national_id'          => $t($row['national_id'] ?? ''),
            'station'              => $t($row['station'] ?? ''),
            'present_address'      => $t($row['present_address'] ?? ''),
            'home_address'         => $t($row['home_address'] ?? ''),
            'next_of_kin_name'     => $t($row['next_of_kin_name'] ?? ''),
            'next_of_kin_phone'    => $t($row['next_of_kin_phone'] ?? ''),
            'next_of_kin_relation' => $t($row['next_of_kin_relation'] ?? ''),
            'join_date'            => $t($row['join_date'] ?? ''),
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
        foreach (['first_name','last_name','gender','date_of_birth','phone','email',
                  'national_id','station','present_address','home_address',
                  'next_of_kin_name','next_of_kin_phone','next_of_kin_relation','join_date'] as $f) {
            $clean[$f] = $s($row[$f] ?? '');
        }
        $clean['email'] = strtolower($clean['email']);
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

        foreach ($rows as $raw) {
            $row = $this->coerceRow($raw);
            $lenCheck = $this->sanitizeRow($row); // length checks only; never persisted/returned
            $errors = [];

            if (empty($row['first_name']) && empty($row['last_name'])) {
                $errors[] = 'Name missing';
            } else {
                if ($row['first_name'] !== '' && strlen($row['first_name']) < 2) $errors[] = 'First name too short';
                if ($row['last_name']  !== '' && strlen($row['last_name'])  < 2) $errors[] = 'Last name too short';
                if (strlen($lenCheck['first_name']) > 80) $errors[] = 'First name too long';
                if (strlen($lenCheck['last_name'])  > 80) $errors[] = 'Last name too long';
            }

            if (!in_array($row['gender'], ['Male', 'Female', 'Other'], true)) {
                $errors[] = 'Invalid gender';
            }

            if ($row['date_of_birth'] !== '') {
                $dob = DateTime::createFromFormat('Y-m-d', $row['date_of_birth']);
                if (!$dob || $dob >= new DateTime()) $errors[] = 'Invalid date of birth';
            }

            if (empty($row['phone'])) {
                $errors[] = 'Phone missing';
            } elseif (!preg_match('/^[+0-9][\d\s\-().]{6,19}$/', $row['phone'])) {
                $errors[] = 'Invalid phone format';
            } elseif (strlen($lenCheck['phone']) > 20) {
                $errors[] = 'Phone too long';
            } elseif ($this->model->phoneExists($row['phone'])) {
                $errors[] = 'Phone already registered';
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

            if (empty($row['national_id'])) {
                $errors[] = 'NIN missing';
            } elseif (strlen($row['national_id']) < 5) {
                $errors[] = 'NIN too short';
            } elseif (strlen($lenCheck['national_id']) > 50) {
                $errors[] = 'NIN too long';
            } elseif ($this->model->nationalIdExists($row['national_id'])) {
                $errors[] = 'NIN already registered';
            }

            if ($row['join_date'] !== '' && !DateTime::createFromFormat('Y-m-d', $row['join_date'])) {
                $errors[] = 'Invalid join date';
            }

            if (strlen($lenCheck['station']) > 200) $errors[] = 'Station too long';
            if (strlen($lenCheck['next_of_kin_name']) > 150) $errors[] = 'Next of kin name too long';
            if (strlen($lenCheck['next_of_kin_phone']) > 20) $errors[] = 'Next of kin phone too long';
            if (strlen($lenCheck['next_of_kin_relation']) > 100) $errors[] = 'Next of kin relation too long';

            if (empty($errors)) { $row['status'] = 'ready'; $row['error'] = ''; $ready++; }
            else { $row['status'] = 'error'; $row['error'] = implode('; ', $errors); $errorCount++; }

            $validated[] = $row;
        }

        return ['rows' => $validated, 'total' => count($rows), 'ready' => $ready, 'errors' => $errorCount, 'skipped' => $skipCount];
    }
}
