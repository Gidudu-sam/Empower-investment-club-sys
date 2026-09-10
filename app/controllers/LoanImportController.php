<?php
require_once CORE_PATH . '/Controller.php';
require_once APP_PATH  . '/models/LoanModel.php';
require_once APP_PATH  . '/models/MemberModel.php';
require_once APP_PATH  . '/models/LoanProductModel.php';

/**
 * LoanImportController — Import loans from Excel/CSV
 *
 * Stage 13-F2 (2026-09): verifyCsrf() was already defined here but never
 * called anywhere in the file -- process() had zero CSRF protection despite
 * looking, at a glance, like it had a CSRF mechanism. It is now actually
 * enforced in process(). process() also previously trusted the client-
 * resubmitted `status === 'ready'` flag as if it were an authoritative
 * decision, and cast loan_amount/duration/interest_rate with bare
 * (float)/(int) with no rejection of zero/negative/non-numeric values --
 * both closed by re-running validateRows() authoritatively, server-side,
 * on the resubmitted raw rows immediately before committing, exactly
 * mirroring MemberImportController's Stage 13-F2 fix. File size and
 * row-count limits added to preview()/parseCSV() for the same reason.
 */
class LoanImportController extends Controller
{
    private LoanModel        $loanModel;
    private MemberModel      $memberModel;
    private LoanProductModel $productModel;

    /** Stage 13-F2: same conservative ceiling rationale as
     *  MemberImportController -- current loan book is small; this gives
     *  generous headroom while bounding worst-case parse cost. */
    private const MAX_FILE_BYTES = 5 * 1024 * 1024; // 5 MB
    private const MAX_ROWS       = 2000;

    public function __construct()
    {
        $this->loanModel   = new LoanModel();
        $this->memberModel = new MemberModel();
        $this->productModel = new LoanProductModel();
    }

    private function requireAdmin(): void
    {
        Session::requireAuth();
        if (Session::get('user_role') !== 'admin') {
            Session::flash('error', 'Access denied.');
            $this->redirect(APP_URL . '/index.php?page=loans');
            exit;
        }
    }

    private function getCsrf(): string
    {
        if (!Session::has('csrf_token')) Session::set('csrf_token', bin2hex(random_bytes(32)));
        return Session::get('csrf_token');
    }

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

        $this->render('loans/import', [
            'pageTitle'   => 'Import Loans — ' . APP_NAME,
            'breadcrumbs' => [
                ['label' => 'Loans', 'url' => APP_URL . '/index.php?page=loans'],
                ['label' => 'Import'],
            ],
            'csrfToken' => $this->getCsrf(),
            'success'   => Session::flash('success'),
            'error'     => Session::flash('error'),
        ]);
    }

    // ================================================================
    // UPLOAD & PREVIEW (AJAX)
    // ================================================================

    public function preview(): void
    {
        $this->requireAdmin();

        if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
            $this->json(['error' => 'No file uploaded or upload error.'], 400);
            return;
        }

        $file = $_FILES['import_file'];

        // Stage 13-F2: application-level file size ceiling, independent
        // of php.ini's upload_max_filesize.
        if ($file['size'] > self::MAX_FILE_BYTES) {
            $this->json(['error' => 'File too large. Maximum size is ' . (self::MAX_FILE_BYTES / 1024 / 1024) . ' MB.'], 400);
            return;
        }

        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

        if (!in_array($ext, ['csv', 'xlsx', 'xls'])) {
            $this->json(['error' => 'Invalid file type. Only .csv, .xlsx, .xls allowed.'], 400);
            return;
        }

        // Stage 13-F2: for the CSV path, confirm the uploaded bytes are
        // actually text, not a renamed non-CSV file with a .csv extension
        // (the .xlsx/.xls branch is validated by PhpSpreadsheet's own
        // parser failing to load a non-spreadsheet file, handled below).
        if ($ext === 'csv' && is_uploaded_file($file['tmp_name']) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime  = $finfo ? finfo_file($finfo, $file['tmp_name']) : false;
            if ($finfo) finfo_close($finfo);
            $allowedMime = ['text/plain', 'text/csv', 'application/csv', 'text/x-csv', 'application/vnd.ms-excel', 'inode/x-empty'];
            if ($mime !== false && !in_array($mime, $allowedMime, true)) {
                $this->json(['error' => 'File does not look like a valid CSV file.'], 400);
                return;
            }
        }

        // Parse the file
        $rows = [];
        if ($ext === 'csv') {
            $rows = $this->parseCSV($file['tmp_name']);
            if ($rows === null) {
                $this->json(['error' => 'File has too many rows. Maximum is ' . self::MAX_ROWS . ' per import.'], 400);
                return;
            }
        } else {
            // For .xlsx/.xls — check if PhpSpreadsheet is available
            $autoload = ROOT_PATH . '/vendor/autoload.php';
            if (file_exists($autoload)) {
                require_once $autoload;
                $rows = $this->parseExcel($file['tmp_name']);
            } else {
                $this->json(['error' => 'Excel (.xlsx) support requires PhpSpreadsheet. Please use CSV format or install: composer require phpoffice/phpspreadsheet'], 400);
                return;
            }
        }

        if (empty($rows)) {
            $this->json(['error' => 'No data found in the file.'], 400);
            return;
        }

        // Stage 13-F2: the .xlsx/.xls path has no natural streaming cutoff
        // like parseCSV()'s early-exit, so the row-count ceiling is
        // enforced here for every file type once parsing is done.
        if (count($rows) > self::MAX_ROWS) {
            $this->json(['error' => 'File has too many rows. Maximum is ' . self::MAX_ROWS . ' per import.'], 400);
            return;
        }

        // Validate rows
        $validated = $this->validateRows($rows);

        $this->json([
            'rows'       => $validated['rows'],
            'total'      => $validated['total'],
            'ready'      => $validated['ready'],
            'errors'     => $validated['errors'],
            'skipped'    => $validated['skipped'],
        ]);
    }

    // ================================================================
    // PROCESS IMPORT
    // ================================================================

    public function process(): void
    {
        $this->requireAdmin();

        if (!$this->isPost()) {
            $this->redirect(APP_URL . '/index.php?page=loan-import');
            return;
        }

        // Stage 13-F2: verifyCsrf() was defined in this class all along
        // but never called -- this mutation had zero CSRF protection.
        if (!$this->verifyCsrf($_POST['csrf_token'] ?? '')) {
            Session::flash('error', 'Security token mismatch. Please try again.');
            $this->redirect(APP_URL . '/index.php?page=loan-import');
            return;
        }

        $jsonData = $_POST['import_data'] ?? '';
        $rows = json_decode($jsonData, true);

        if (empty($rows) || !is_array($rows)) {
            Session::flash('error', 'No valid data to import.');
            $this->redirect(APP_URL . '/index.php?page=loan-import');
            return;
        }

        if (count($rows) > self::MAX_ROWS) {
            Session::flash('error', 'Too many rows submitted.');
            $this->redirect(APP_URL . '/index.php?page=loan-import');
            return;
        }

        // Stage 13-F2: the client's `status` field on each resubmitted row
        // (and the raw amount/duration/interest/date fields alongside it)
        // must never be trusted as an authorization or validation
        // decision -- re-run the exact same server-side validation used
        // for preview, from scratch, on the resubmitted raw values, and
        // only ever commit a row this fresh pass marks 'ready'.
        $rows = array_map(function (array $row) {
            unset($row['status'], $row['error']);
            return $row;
        }, $rows);
        $rows = $this->validateRows($rows)['rows'];

        $db = Database::getInstance()->getConnection();
        $imported = 0;
        $skipped  = 0;
        $errors   = 0;
        $log      = [];
        $userId   = (int)Session::get('user_id');

        try {
            $db->beginTransaction();

            foreach ($rows as $row) {
                if (($row['status'] ?? '') !== 'ready') {
                    $skipped++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'skipped', 'message' => $row['error'] ?? 'Not ready'];
                    continue;
                }

                // Find member
                $member = $this->memberModel->findWhere(['member_number' => $row['membership_number']]);
                if (!$member) { $skipped++; continue; }

                // Generate loan number if not provided. Sanitize a
                // client-supplied one exactly once, right here at write
                // time (Stage 13-F2) -- the same single-application
                // convention used throughout this remediation -- since
                // loan_number is free text from the CSV, not a
                // server-generated value, whenever it is provided.
                $loanNumber = !empty($row['loan_number']) ? $this->sanitize((string)$row['loan_number']) : $this->loanModel->generateLoanNumber();

                // Check duplicate
                $existing = $this->loanModel->findWhere(['loan_number' => $loanNumber]);
                if ($existing) {
                    $skipped++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'skipped', 'message' => "Loan {$loanNumber} already exists"];
                    continue;
                }

                // Determine loan type
                $loanTypeId = $this->getLoanTypeId($row['loan_type'] ?? 'Normal Loan');

                // Calculate loan. Stage 13-F2: validateRows() above has
                // already authoritatively rejected zero/negative/
                // non-numeric amounts, non-numeric interest rates and
                // non-positive durations -- this is a defense-in-depth
                // re-check immediately before the value is used, so a
                // future change to validateRows() can never silently
                // reopen the "trust the client amount" gap this stage
                // closes.
                $amount   = (float)$row['loan_amount'];
                $months   = (int)$row['duration_months'];
                $rate     = (float)$row['interest_rate'];
                $procFee  = (float)($row['processing_fee'] ?? 0);

                if (!is_finite($amount) || $amount <= 0 || !is_finite($rate) || $rate < 0 || $months <= 0) {
                    $errors++;
                    $log[] = ['row' => $row['row_num'] ?? 0, 'status' => 'error', 'message' => 'Rejected at commit: invalid amount/rate/duration.'];
                    continue;
                }

                $totalInterest = $amount * ($rate / 100) * $months;
                $totalPayable  = $amount + $totalInterest + $procFee;
                $monthlyInstallment = $months > 0 ? round($totalPayable / $months, 2) : $totalPayable;

                $approvalDate = $row['approval_date'] ?? date('Y-m-d');
                $disbursementDate = $row['disbursement_date'] ?? $approvalDate;
                $firstRepayDate = $row['first_repayment_date'] ?? date('Y-m-d', strtotime('+1 month', strtotime($approvalDate)));

                // Calculate due date
                $dueDate = date('Y-m-d', strtotime("+{$months} months", strtotime($approvalDate)));

                // Create loan
                $loanData = [
                    'loan_number'         => $loanNumber,
                    'member_id'           => (int)$member['id'],
                    'loan_type_id'        => $loanTypeId,
                    'loan_amount'         => $amount,
                    'approved_amount'     => $amount,
                    'interest_rate'       => $rate,
                    'interest_amount'     => round($totalInterest, 2),
                    'processing_fee'      => $procFee,
                    'monthly_installment' => $monthlyInstallment,
                    'total_payable'       => round($totalPayable, 2),
                    'outstanding'         => round($totalPayable, 2),
                    'amount_paid'         => 0,
                    'issue_date'          => $disbursementDate,
                    'approval_date'       => $approvalDate,
                    'disbursement_date'   => $disbursementDate,
                    'due_date'            => $dueDate,
                    'loan_period'         => "{$months} Months",
                    'loan_period_months'  => $months,
                    'next_payment_date'   => $firstRepayDate,
                    'purpose'             => !empty($row['purpose']) ? $this->sanitize((string)$row['purpose']) : null,
                    'status'              => 'active',
                    'recorded_by'         => $userId,
                ];

                $newId = $this->loanModel->create($loanData);

                if ($newId) {
                    // Generate installment schedule
                    $this->loanModel->generateInstallments($newId, $monthlyInstallment, $months, $approvalDate);
                    $imported++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'imported', 'message' => "Loan {$loanNumber} created"];
                } else {
                    $errors++;
                    $log[] = ['row' => $row['row_num'], 'status' => 'error', 'message' => 'Database insert failed'];
                }
            }

            $db->commit();
        } catch (PDOException $e) {
            $db->rollBack();
            Session::flash('error', 'Import failed: ' . $e->getMessage());
            $this->redirect(APP_URL . '/index.php?page=loan-import');
            return;
        }

        // Log activity
        $this->loanModel->log($userId, 'loans_imported', "Imported {$imported} loans from file. Skipped: {$skipped}, Errors: {$errors}");

        // Store results in session for display
        Session::set('import_result', [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
            'total'    => count($rows),
            'log'      => $log,
        ]);

        Session::flash('success', "Import completed: {$imported} loans imported, {$skipped} skipped, {$errors} errors.");
        $this->redirect(APP_URL . '/index.php?page=loan-import');
    }

    // ================================================================
    // DOWNLOAD TEMPLATE
    // ================================================================

    public function template(): void
    {
        $this->requireAdmin();

        $headers = [
            'Membership Number',
            'Member Name',
            'Loan Number',
            'Loan Type',
            'Loan Amount',
            'Purpose',
            'Interest Rate',
            'Interest Type',
            'Loan Duration (Months)',
            'Processing Fee',
            'Approval Date',
            'Disbursement Date',
            'First Repayment Date',
        ];

        $sample = [
            'EMP0001',
            'Ssali Frank',
            '',
            'Business Loan',
            '5000000',
            'Business expansion',
            '5',
            'Flat',
            '6',
            '150000',
            date('Y-m-d'),
            date('Y-m-d'),
            date('Y-m-d', strtotime('+1 month')),
        ];

        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="loan_import_template.csv"');

        $output = fopen('php://output', 'w');
        fputcsv($output, $headers);
        fputcsv($output, $sample);
        fclose($output);
        exit;
    }

    // ================================================================
    // HELPERS
    // ================================================================

    /**
     * Stage 13-F2: returns null (distinct from an empty array) once the
     * file exceeds MAX_ROWS, so preview() can reject the whole file with
     * a clear message instead of silently importing a truncated subset.
     */
    private function parseCSV(string $filepath): ?array
    {
        $rows = [];
        $handle = fopen($filepath, 'r');
        if (!$handle) return [];

        $headers = fgetcsv($handle);
        if (!$headers) { fclose($handle); return []; }

        // Normalize headers
        $headers = array_map(fn($h) => strtolower(trim($h)), $headers);

        $rowNum = 1;
        while (($data = fgetcsv($handle)) !== false) {
            $rowNum++;
            if (count($data) < 5) continue; // Skip empty rows

            if (count($rows) >= self::MAX_ROWS) {
                fclose($handle);
                return null;
            }

            $row = [];
            foreach ($headers as $i => $h) {
                $row[$h] = trim($data[$i] ?? '');
            }
            $row['row_num'] = $rowNum;
            $rows[] = $this->mapColumns($row);
        }

        fclose($handle);
        return $rows;
    }

    private function parseExcel(string $filepath): array
    {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filepath);
        $sheet = $spreadsheet->getActiveSheet();
        $rows  = [];

        $headers = [];
        $rowNum  = 0;

        foreach ($sheet->getRowIterator() as $row) {
            $rowNum++;
            $cells = [];
            foreach ($row->getCellIterator() as $cell) {
                $cells[] = trim((string)$cell->getValue());
            }

            if ($rowNum === 1) {
                $headers = array_map(fn($h) => strtolower($h), $cells);
                continue;
            }

            if (empty(array_filter($cells))) continue;

            $mapped = [];
            foreach ($headers as $i => $h) {
                $mapped[$h] = $cells[$i] ?? '';
            }
            $mapped['row_num'] = $rowNum;
            $rows[] = $this->mapColumns($mapped);
        }

        return $rows;
    }

    private function mapColumns(array $row): array
    {
        return [
            'row_num'              => $row['row_num'] ?? 0,
            // Standard template columns
            'membership_number'    => $row['membership number'] ?? $row['membership_number'] ?? $row['member number'] ?? $row['member no'] ?? '',
            'member_name'          => $row['member name'] ?? $row['member_name'] ?? $row['contact person'] ?? $row['name'] ?? '',
            'loan_number'          => $row['loan number'] ?? $row['loan_number'] ?? $row['loan no'] ?? '',
            'loan_type'            => $row['loan type'] ?? $row['loan_type'] ?? 'Business Loan',
            'loan_amount'          => $row['loan amount'] ?? $row['loan_amount'] ?? $row['amount'] ?? 0,
            'purpose'              => $row['purpose'] ?? $row['business name'] ?? $row['business'] ?? '',
            'interest_rate'        => $row['interest rate'] ?? $row['interest_rate'] ?? $row['interest pe'] ?? $row['interest'] ?? $row['rate'] ?? 0,
            'interest_type'        => $row['interest type'] ?? $row['interest_type'] ?? 'Flat',
            'duration_months'      => $row['loan duration (months)'] ?? $row['duration_months'] ?? $row['loan duration'] ?? $row['period'] ?? $row['duration'] ?? $row['months'] ?? 0,
            'processing_fee'       => $row['processing fee'] ?? $row['processing_fee'] ?? 0,
            'approval_date'        => $row['approval date'] ?? $row['approval_date'] ?? $row['date of issue'] ?? $row['date of issu'] ?? $row['date'] ?? $row['issue date'] ?? '',
            'disbursement_date'    => $row['disbursement date'] ?? $row['disbursement_date'] ?? $row['date of issue'] ?? $row['date of issu'] ?? '',
            'first_repayment_date' => $row['first repayment date'] ?? $row['first_repayment_date'] ?? '',
            // Extra fields from your format
            'business_name'        => $row['business name'] ?? '',
            'contact'              => $row['contact'] ?? $row['phone'] ?? '',
            'weekly_minimum'       => $row['weekly minim'] ?? $row['weekly minimum'] ?? $row['weekly savings'] ?? 0,
            'total_monthly'        => $row['total month'] ?? $row['total monthly'] ?? $row['monthly total'] ?? 0,
        ];
    }

    private function validateRows(array $rows): array
    {
        $validated = [];
        $readyCount = 0;
        $errorCount = 0;
        $skipCount  = 0;

        foreach ($rows as $row) {
            $errors = [];

            // Check member exists
            if (empty($row['membership_number'])) {
                // Try to find member by name or phone
                if (!empty($row['member_name'])) {
                    $nameParts = explode(' ', trim($row['member_name']), 2);
                    $firstName = $nameParts[0] ?? '';
                    $lastName  = $nameParts[1] ?? '';
                    if ($firstName) {
                        $searchResult = $this->memberModel->search($firstName, '', '', '', 1, 5);
                        if (!empty($searchResult['rows'])) {
                            $row['membership_number'] = $searchResult['rows'][0]['member_number'];
                        } else {
                            $errors[] = 'Member not found: ' . $row['member_name'];
                        }
                    } else {
                        $errors[] = 'Membership number and name missing';
                    }
                } elseif (!empty($row['contact'])) {
                    // Search by phone
                    $member = $this->memberModel->findWhere(['phone' => $row['contact']]);
                    if ($member) {
                        $row['membership_number'] = $member['member_number'];
                    } else {
                        $errors[] = 'Member not found by contact: ' . $row['contact'];
                    }
                } else {
                    $errors[] = 'Membership number missing';
                }
            } else {
                $member = $this->memberModel->findWhere(['member_number' => $row['membership_number']]);
                if (!$member) {
                    $errors[] = 'Member not found';
                }
            }

            // Loan amount — handle commas and spaces. Stage 13-F2: also
            // reject non-finite values (a huge/scientific-notation string
            // that is_numeric() accepts but that overflows to INF as a
            // float) -- amount must exist, be numeric, finite, and > 0.
            $amountRaw = str_replace([',', ' '], '', (string)$row['loan_amount']);
            if ($amountRaw === '' || !is_numeric($amountRaw) || !is_finite((float)$amountRaw) || (float)$amountRaw <= 0) {
                $errors[] = 'Invalid loan amount';
            } else {
                $row['loan_amount'] = $amountRaw;
            }

            // Duration — parse from various formats (e.g. "6", "6 Months", "6 MONTHLY")
            $durationRaw = $row['duration_months'];
            $duration = (int)preg_replace('/[^0-9]/', '', (string)$durationRaw);
            if ($duration <= 0) {
                $errors[] = 'Invalid duration';
            } else {
                $row['duration_months'] = $duration;
            }

            // Interest rate — Stage 13-F2: must be numeric, finite, and
            // non-negative (a negative rate is not a valid business value
            // and was previously accepted as long as is_numeric() passed).
            if (!is_numeric($row['interest_rate']) || !is_finite((float)$row['interest_rate']) || (float)$row['interest_rate'] < 0) {
                $errors[] = 'Invalid interest rate';
            }

            // Dates
            if (!empty($row['approval_date']) && !strtotime($row['approval_date'])) {
                $errors[] = 'Invalid approval date';
            }

            // Stage 13-F2: loan_number is free text from the CSV -- cap it
            // to the column's actual size (loans.loan_number varchar(20))
            // so an oversized value is rejected here, not at the DB layer.
            if (!empty($row['loan_number']) && strlen((string)$row['loan_number']) > 20) {
                $errors[] = 'Loan number too long';
            }

            // Check duplicate loan number
            if (!empty($row['loan_number'])) {
                $existing = $this->loanModel->findWhere(['loan_number' => $row['loan_number']]);
                if ($existing) {
                    $errors[] = "Loan {$row['loan_number']} already exists";
                    $skipCount++;
                    $row['status'] = 'skipped';
                    $row['error']  = implode('; ', $errors);
                    $validated[] = $row;
                    continue;
                }
            }

            if (empty($errors)) {
                $row['status'] = 'ready';
                $row['error']  = '';
                $readyCount++;
            } else {
                $row['status'] = 'error';
                $row['error']  = implode('; ', $errors);
                $errorCount++;
            }

            $validated[] = $row;
        }

        return [
            'rows'    => $validated,
            'total'   => count($rows),
            'ready'   => $readyCount,
            'errors'  => $errorCount,
            'skipped' => $skipCount,
        ];
    }

    private function getLoanTypeId(string $name): int
    {
        $map = [
            'normal loan'          => 1,
            'normal'               => 1,
            'business loan'        => 2,
            'business'             => 2,
            'asset financing loan' => 3,
            'asset financing'      => 3,
            'asset'                => 3,
            'executive loan'       => 4,
            'executive'            => 4,
        ];
        return $map[strtolower(trim($name))] ?? 1;
    }
}
