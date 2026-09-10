<?php
/**
 * TransactionInvestigationService — SA-4 (Transaction Investigation &
 * Forensic Traceability, 2026-09).
 *
 * READ-ONLY. Every method here only ever SELECTs. This is an evidence
 * ASSEMBLER, not a corrector: no method posts a journal, reverses a
 * transaction, edits a record, or calls JournalService::post()/reverse().
 * If SA-3 or a human reviewer determines a record needs fixing, that
 * happens in SA-5 through a separate, controlled, audited workflow.
 *
 * Every trace*() method returns the same evidence-panel shape (see
 * Section 19 of the SA-4 brief):
 *   entity_type, reference, facts[], journal, journal_lines[], timeline[],
 *   audit[], warnings[], missing[], known_dummy[], related[]
 * -- 'facts' is only what is directly stored or directly derivable from a
 * real FK relationship; 'warnings' need human interpretation; 'missing'
 * is information that cannot currently be established; 'known_dummy'
 * flags data already documented elsewhere in this engagement (Stage
 * 19D/24-28) as pre-enforcement test data, never presented as fact.
 *
 * Historical/cutover handling: this system has no per-transaction
 * "created before/after cutover" column. The only real signal is
 * journal_entries.data_classification ('live'/'dummy'/'unknown', added
 * in Stage 26). A transaction with NO journal at all cannot be classified
 * this way (the column lives on the journal, not the source row) -- for
 * those, this service reports the fact plainly ("no journal_entry_id and
 * no matching journal_entries.source_reference_id") and explicitly labels
 * the cutover-vs-defect question as requiring human interpretation
 * against the documented baseline, never guessing an answer.
 */
class TransactionInvestigationService
{
    private PDO $db;
    private const SEARCH_LIMIT = 25;

    public function __construct()
    {
        $this->db = Database::getInstance()->getConnection();
    }

    // ================================================================
    // SEARCH (bounded, parameterized -- never accepts a table name or
    // SQL fragment; $type is validated against a fixed allow-list)
    // ================================================================
    private const SEARCH_TYPES = ['member', 'savings', 'loan', 'repayment', 'withdrawal', 'fee', 'journal'];

    /** @return array<int, array{entity_type:string,reference:string,id:int,member:?string,date:?string,amount:?float,status:?string,journal_status:string}> */
    public function search(string $term, string $type = ''): array
    {
        $term = trim($term);
        if ($term === '') { return []; }
        if ($type !== '' && !in_array($type, self::SEARCH_TYPES, true)) { $type = ''; }

        $results = [];
        $types = $type === '' ? self::SEARCH_TYPES : [$type];
        $perType = $type === '' ? 6 : self::SEARCH_LIMIT;

        foreach ($types as $t) {
            $method = 'search' . ucfirst($t);
            $results = array_merge($results, $this->$method($term, $perType));
        }
        return array_slice($results, 0, self::SEARCH_LIMIT);
    }

    private function searchMember(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT id, member_number, first_name, last_name, phone, email, join_date, status
            FROM members
            WHERE member_number LIKE ? OR account_number LIKE ? OR first_name LIKE ? OR last_name LIKE ?
               OR CONCAT(first_name,' ',last_name) LIKE ? OR phone LIKE ? OR email LIKE ?
            ORDER BY id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like, $like, $like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Member', 'reference' => $r['member_number'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['join_date'],
                'amount' => null, 'status' => $r['status'], 'journal_status' => 'n/a',
            ];
        }
        return $out;
    }

    private function searchSavings(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT s.id, s.receipt_number, s.transaction_date, s.credit, s.debit, s.journal_entry_id,
                   m.first_name, m.last_name
            FROM savings s
            JOIN members m ON m.id = s.member_id
            WHERE s.receipt_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?
            ORDER BY s.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Savings Transaction', 'reference' => $r['receipt_number'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['transaction_date'],
                'amount' => (float)($r['credit'] ?: $r['debit']), 'status' => null,
                'journal_status' => $r['journal_entry_id'] ? 'Linked' : 'Not linked',
            ];
        }
        return $out;
    }

    private function searchLoan(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT l.id, l.loan_number, l.issue_date, l.loan_amount, l.status, l.journal_entry_id,
                   m.first_name, m.last_name
            FROM loans l
            JOIN members m ON m.id = l.member_id
            WHERE l.loan_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?
            ORDER BY l.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Loan', 'reference' => $r['loan_number'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['issue_date'],
                'amount' => (float)$r['loan_amount'], 'status' => $r['status'],
                'journal_status' => $r['journal_entry_id'] ? 'Linked' : 'Not linked',
            ];
        }
        return $out;
    }

    private function searchRepayment(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT r.id, r.repayment_number, r.payment_date, r.amount_paid, r.journal_entry_id,
                   m.first_name, m.last_name
            FROM loan_repayments r
            JOIN members m ON m.id = r.member_id
            WHERE r.repayment_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?
            ORDER BY r.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Loan Repayment', 'reference' => $r['repayment_number'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['payment_date'],
                'amount' => (float)$r['amount_paid'], 'status' => null,
                'journal_status' => $r['journal_entry_id'] ? 'Linked' : 'Not linked',
            ];
        }
        return $out;
    }

    private function searchWithdrawal(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT w.id, w.withdrawal_number, w.withdrawal_date, w.withdrawal_amount, w.journal_entry_id,
                   m.first_name, m.last_name
            FROM withdrawals w
            JOIN members m ON m.id = w.member_id
            WHERE w.withdrawal_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?
            ORDER BY w.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Withdrawal', 'reference' => $r['withdrawal_number'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['withdrawal_date'],
                'amount' => (float)$r['withdrawal_amount'], 'status' => null,
                'journal_status' => $r['journal_entry_id'] ? 'Linked' : 'Not linked',
            ];
        }
        return $out;
    }

    private function searchFee(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT f.id, f.reference_number, f.charged_date, f.amount, f.status, f.journal_entry_id,
                   m.first_name, m.last_name, ft.fee_name
            FROM member_fees f
            JOIN members m ON m.id = f.member_id
            JOIN fees ft ON ft.id = f.fee_id
            WHERE f.reference_number LIKE ? OR m.member_number LIKE ? OR CONCAT(m.first_name,' ',m.last_name) LIKE ?
            ORDER BY f.id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like, $like, $like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'entity_type' => 'Fee Charge', 'reference' => $r['reference_number'] ?: ('#' . $r['id']) . ' ' . $r['fee_name'], 'id' => (int)$r['id'],
                'member' => trim($r['first_name'] . ' ' . $r['last_name']), 'date' => $r['charged_date'],
                'amount' => (float)$r['amount'], 'status' => $r['status'],
                'journal_status' => $r['journal_entry_id'] ? 'Linked' : 'Not linked',
            ];
        }
        return $out;
    }

    private function searchJournal(string $term, int $limit): array
    {
        $like = "%{$term}%";
        $stmt = $this->db->prepare("
            SELECT id, entry_number, entry_date, source_module, data_classification
            FROM journal_entries
            WHERE entry_number LIKE ?
            ORDER BY id DESC LIMIT " . (int)$limit
        );
        $stmt->execute([$like]);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $totals = $this->db->prepare("SELECT COALESCE(SUM(debit),0) FROM journal_lines WHERE journal_entry_id = ?");
            $totals->execute([$r['id']]);
            $out[] = [
                'entity_type' => 'Journal Entry', 'reference' => $r['entry_number'], 'id' => (int)$r['id'],
                'member' => null, 'date' => $r['entry_date'], 'amount' => (float)$totals->fetchColumn(),
                'status' => $r['data_classification'], 'journal_status' => 'n/a',
            ];
        }
        return $out;
    }

    // ================================================================
    // Shared helpers
    // ================================================================

    private function panel(string $entityType, string $reference): array
    {
        return [
            'entity_type' => $entityType, 'reference' => $reference,
            'facts' => [], 'journal' => null, 'journal_lines' => [], 'timeline' => [],
            'audit' => [], 'warnings' => [], 'missing' => [], 'known_dummy' => [], 'related' => [],
        ];
    }

    private function notFound(string $entityType, string $reference, string $message): array
    {
        $p = $this->panel($entityType, $reference);
        $p['missing'] = [$message];
        return $p;
    }

    /** Attaches journal + lines + a resolved link/missing note to a panel, given a nullable journal_entry_id and a (source_module, source_reference_id) fallback lookup. */
    private function attachJournal(array &$panel, ?int $journalEntryId, string $sourceModule, int $sourceReferenceId): void
    {
        $je = null;
        if ($journalEntryId) {
            $stmt = $this->db->prepare("SELECT * FROM journal_entries WHERE id = ?");
            $stmt->execute([$journalEntryId]);
            $je = $stmt->fetch();
        }
        if (!$je) {
            // Fall back to source_module/source_reference_id, since some
            // records only carry the relationship on the journal side.
            $stmt = $this->db->prepare("SELECT * FROM journal_entries WHERE source_module = ? AND source_reference_id = ? LIMIT 1");
            $stmt->execute([$sourceModule, $sourceReferenceId]);
            $je = $stmt->fetch();
        }

        if (!$je) {
            $panel['missing'][] = 'No linked journal entry (checked both the direct journal_entry_id column and journal_entries.source_module/source_reference_id).';
            $panel['journal'] = null;
            return;
        }

        $lines = $this->db->prepare("
            SELECT jl.id, jl.debit, jl.credit, jl.description, a.code, a.name
            FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id
            WHERE jl.journal_entry_id = ? ORDER BY jl.id
        ");
        $lines->execute([$je['id']]);
        $lineRows = $lines->fetchAll();
        $totalDebit = array_sum(array_column($lineRows, 'debit'));
        $totalCredit = array_sum(array_column($lineRows, 'credit'));

        $panel['journal'] = [
            'id' => (int)$je['id'], 'entry_number' => $je['entry_number'], 'entry_date' => $je['entry_date'],
            'source_module' => $je['source_module'], 'source_reference_id' => $je['source_reference_id'],
            'data_classification' => $je['data_classification'], 'posted' => (bool)$je['posted'],
            'reversed' => (bool)$je['reversed'], 'status' => $je['status'],
            'total_debit' => $totalDebit, 'total_credit' => $totalCredit, 'difference' => round($totalDebit - $totalCredit, 2),
        ];
        $panel['journal_lines'] = array_map(fn($l) => [
            'account' => "{$l['code']} — {$l['name']}", 'debit' => (float)$l['debit'], 'credit' => (float)$l['credit'], 'description' => $l['description'],
        ], $lineRows);

        if ($je['data_classification'] !== 'live') {
            $panel['known_dummy'][] = "Linked journal {$je['entry_number']} is classified '{$je['data_classification']}' -- treat any imbalance/anomaly on it as known test data, not confirmed corruption, per project memory (Stage 24-28).";
        }
        if (abs($panel['journal']['difference']) > 0.01) {
            $panel['warnings'][] = "Linked journal {$je['entry_number']} does not balance (debit {$totalDebit} vs credit {$totalCredit}).";
        }
        $this->attachJournalAudit($panel, (int)$je['id']);
    }

    private function attachJournalAudit(array &$panel, int $journalEntryId): void
    {
        $stmt = $this->db->prepare("
            SELECT a.id, u.full_name, a.action, a.reason, a.created_at
            FROM journal_entry_audit a LEFT JOIN users u ON u.id = a.user_id
            WHERE a.entity_type = 'journal_entry' AND a.entity_id = ? ORDER BY a.created_at
        ");
        $stmt->execute([$journalEntryId]);
        foreach ($stmt->fetchAll() as $a) {
            $actor = $a['full_name'] ?? ('user #' . ($a['user_id'] ?? '?'));
            $panel['audit'][] = [
                'source' => 'journal_entry_audit', 'actor' => $actor,
                'action' => $a['action'], 'reason' => $a['reason'], 'when' => $a['created_at'],
            ];
        }
    }

    /** Best-effort activity_logs correlation: this table has no entity_id/entity_type columns, only a free-text description, so matching is a LIKE on the record's own reference number -- explicitly labeled as best-effort, never claimed as exact. */
    private function attachActivityLogTextMatch(array &$panel, string $referenceNumber): void
    {
        if ($referenceNumber === '') { return; }
        $stmt = $this->db->prepare("SELECT al.id, u.full_name, al.action, al.description, al.created_at FROM activity_logs al LEFT JOIN users u ON u.id = al.user_id WHERE al.description LIKE ? ORDER BY al.created_at LIMIT 10");
        $stmt->execute(["%{$referenceNumber}%"]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $a) {
            $panel['audit'][] = [
                'source' => 'activity_logs (best-effort text match, not a foreign key)', 'actor' => $a['full_name'] ?? "user #?",
                'action' => $a['action'], 'reason' => $a['description'], 'when' => $a['created_at'],
            ];
        }
        if (empty($rows)) {
            $panel['missing'][] = 'No activity_logs entry text-matches this reference number (activity_logs has no direct foreign key to this record, so absence here is not conclusive).';
        }
    }

    // ================================================================
    // MEMBER-CENTRIC TRACE
    // ================================================================
    public function traceMember(int $memberId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $m = $stmt->fetch();
        if (!$m) { return $this->notFound('Member', "#{$memberId}", 'Member record not found.'); }

        $panel = $this->panel('Member', $m['member_number']);
        $panel['facts'] = [
            ['label' => 'Name', 'value' => trim($m['first_name'] . ' ' . $m['last_name'])],
            ['label' => 'Status', 'value' => $m['status']],
            ['label' => 'Joined', 'value' => $m['join_date']],
            ['label' => 'Phone', 'value' => $m['phone']],
        ];

        $accounts = $this->db->prepare("SELECT a.id, a.account_number, a.account_type, a.status, a.opened_date FROM member_savings_accounts a JOIN savings_account_holders h ON h.account_id = a.id WHERE h.member_id = ? ORDER BY a.id");
        $accounts->execute([$memberId]);
        $panel['related'][] = ['label' => 'Savings Accounts', 'items' => array_map(fn($a) => [
            'label' => "{$a['account_number']} ({$a['account_type']}, {$a['status']})", 'route' => null, 'id' => (int)$a['id'],
        ], $accounts->fetchAll())];

        $sav = $this->db->prepare("SELECT id, receipt_number, transaction_date, credit, debit, journal_entry_id FROM savings WHERE member_id = ? ORDER BY transaction_date DESC LIMIT 20");
        $sav->execute([$memberId]);
        $panel['related'][] = ['label' => 'Savings Transactions (most recent 20)', 'items' => array_map(fn($s) => [
            'label' => "{$s['receipt_number']} — " . ($s['transaction_date']) . ' — ' . ($s['journal_entry_id'] ? 'linked' : 'NOT LINKED'), 'route' => 'investigation-savings', 'id' => (int)$s['id'],
        ], $sav->fetchAll())];

        $loans = $this->db->prepare("SELECT id, loan_number, status, loan_amount, journal_entry_id FROM loans WHERE member_id = ? ORDER BY id DESC");
        $loans->execute([$memberId]);
        $panel['related'][] = ['label' => 'Loans', 'items' => array_map(fn($l) => [
            'label' => "{$l['loan_number']} ({$l['status']}, " . number_format((float)$l['loan_amount']) . ')', 'route' => 'investigation-loan', 'id' => (int)$l['id'],
        ], $loans->fetchAll())];

        $wd = $this->db->prepare("SELECT id, withdrawal_number, withdrawal_date, withdrawal_amount FROM withdrawals WHERE member_id = ? ORDER BY id DESC");
        $wd->execute([$memberId]);
        $panel['related'][] = ['label' => 'Withdrawals', 'items' => array_map(fn($w) => [
            'label' => "{$w['withdrawal_number']} — {$w['withdrawal_date']}", 'route' => 'investigation-withdrawal', 'id' => (int)$w['id'],
        ], $wd->fetchAll())];

        $fees = $this->db->prepare("SELECT f.id, f.reference_number, f.status, f.amount, ft.fee_name FROM member_fees f JOIN fees ft ON ft.id = f.fee_id WHERE f.member_id = ? ORDER BY f.id DESC");
        $fees->execute([$memberId]);
        $panel['related'][] = ['label' => 'Fee Charges', 'items' => array_map(fn($f) => [
            'label' => "{$f['fee_name']} — {$f['status']} — " . number_format((float)$f['amount']), 'route' => 'investigation-fee', 'id' => (int)$f['id'],
        ], $fees->fetchAll())];

        $adj = $this->db->prepare("SELECT id, adjustment_number, status, amount FROM member_account_adjustments WHERE member_id = ? ORDER BY id DESC");
        $adj->execute([$memberId]);
        $panel['related'][] = ['label' => 'Account Adjustments', 'items' => array_map(fn($a) => [
            'label' => "{$a['adjustment_number']} — {$a['status']}", 'route' => null, 'id' => (int)$a['id'],
        ], $adj->fetchAll())];

        return $panel;
    }

    // ================================================================
    // SAVINGS TRANSACTION TRACE
    // ================================================================
    public function traceSavingsTransaction(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT s.*, m.member_number, m.first_name, m.last_name, a.account_number, a.account_type
            FROM savings s
            JOIN members m ON m.id = s.member_id
            LEFT JOIN member_savings_accounts a ON a.id = s.savings_account_id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        $s = $stmt->fetch();
        if (!$s) { return $this->notFound('Savings Transaction', "#{$id}", 'Savings transaction not found.'); }

        $panel = $this->panel('Savings Transaction', $s['receipt_number']);
        $panel['facts'] = [
            ['label' => 'Member', 'value' => trim($s['first_name'] . ' ' . $s['last_name']) . " ({$s['member_number']})"],
            ['label' => 'Savings Account', 'value' => $s['account_number'] ? "{$s['account_number']} ({$s['account_type']})" : 'none on record'],
            ['label' => 'Transaction Type', 'value' => $s['transaction_type']],
            ['label' => 'Date', 'value' => $s['transaction_date']],
            ['label' => 'Amount (Credit)', 'value' => $s['credit']], ['label' => 'Amount (Debit)', 'value' => $s['debit']],
            ['label' => 'Running Balance', 'value' => $s['running_balance']],
            ['label' => 'Recorded By (user id)', 'value' => $s['recorded_by']],
            ['label' => 'Created At', 'value' => $s['created_at']],
        ];
        $panel['related'][] = ['label' => 'Member', 'items' => [['label' => trim($s['first_name'] . ' ' . $s['last_name']), 'route' => 'investigation-member', 'id' => (int)$s['member_id']]]];

        $this->attachJournal($panel, $s['journal_entry_id'] ? (int)$s['journal_entry_id'] : null, 'savings', $id);
        $this->attachActivityLogTextMatch($panel, $s['receipt_number']);

        $panel['timeline'] = [
            ['when' => $s['created_at'], 'what' => 'Savings transaction recorded', 'found' => true],
            ['when' => $panel['journal']['entry_date'] ?? null, 'what' => 'Journal entry posted', 'found' => $panel['journal'] !== null],
        ];

        return $panel;
    }

    // ================================================================
    // LOAN TRACE
    // ================================================================
    public function traceLoan(int $id): array
    {
        $stmt = $this->db->prepare("SELECT l.*, m.member_number, m.first_name, m.last_name FROM loans l JOIN members m ON m.id = l.member_id WHERE l.id = ?");
        $stmt->execute([$id]);
        $l = $stmt->fetch();
        if (!$l) { return $this->notFound('Loan', "#{$id}", 'Loan not found.'); }

        $panel = $this->panel('Loan', $l['loan_number']);
        $panel['facts'] = [
            ['label' => 'Member', 'value' => trim($l['first_name'] . ' ' . $l['last_name']) . " ({$l['member_number']})"],
            ['label' => 'Status', 'value' => $l['status']],
            ['label' => 'Principal (loan_amount)', 'value' => $l['loan_amount']],
            ['label' => 'Interest Rate', 'value' => $l['interest_rate']],
            ['label' => 'Interest Amount', 'value' => $l['interest_amount']],
            ['label' => 'Processing Fee', 'value' => $l['processing_fee']],
            ['label' => 'Total Payable', 'value' => $l['total_payable']],
            ['label' => 'Amount Paid', 'value' => $l['amount_paid']],
            ['label' => 'Outstanding', 'value' => $l['outstanding']],
            ['label' => 'Penalty Total', 'value' => $l['penalty_total']],
            ['label' => 'Issue Date', 'value' => $l['issue_date']],
            ['label' => 'Due Date', 'value' => $l['due_date']],
            ['label' => 'Disbursement Date', 'value' => $l['disbursement_date']],
        ];
        $panel['related'][] = ['label' => 'Member', 'items' => [['label' => trim($l['first_name'] . ' ' . $l['last_name']), 'route' => 'investigation-member', 'id' => (int)$l['member_id']]]];

        $inst = $this->db->prepare("SELECT id, installment_no, due_date, amount_due, amount_paid, status FROM loan_installments WHERE loan_id = ? ORDER BY installment_no");
        $inst->execute([$id]);
        $installments = $inst->fetchAll();
        $panel['related'][] = ['label' => 'Installments (' . count($installments) . ')', 'items' => array_map(fn($i) => [
            'label' => "#{$i['installment_no']} due {$i['due_date']} — {$i['status']} (" . number_format((float)$i['amount_paid']) . '/' . number_format((float)$i['amount_due']) . ')', 'route' => null, 'id' => (int)$i['id'],
        ], $installments)];

        $rep = $this->db->prepare("SELECT id, repayment_number, payment_date, amount_paid, journal_entry_id FROM loan_repayments WHERE loan_id = ? ORDER BY payment_date, id");
        $rep->execute([$id]);
        $repayments = $rep->fetchAll();
        $panel['related'][] = ['label' => 'Repayments (' . count($repayments) . ')', 'items' => array_map(fn($r) => [
            'label' => "{$r['repayment_number']} — {$r['payment_date']} — " . number_format((float)$r['amount_paid']) . ' — ' . ($r['journal_entry_id'] ? 'linked' : 'NOT LINKED'), 'route' => 'investigation-repayment', 'id' => (int)$r['id'],
        ], $repayments)];
        $unlinked = count(array_filter($repayments, fn($r) => !$r['journal_entry_id']));
        if ($unlinked > 0) {
            $panel['warnings'][] = "{$unlinked} of " . count($repayments) . " repayment(s) on this loan have no direct journal_entry_id (see each repayment's own trace for the source_module fallback check).";
        }

        $this->attachJournal($panel, $l['journal_entry_id'] ? (int)$l['journal_entry_id'] : null, 'loans', $id);
        $this->attachActivityLogTextMatch($panel, $l['loan_number']);

        $panel['timeline'] = array_filter([
            $l['application_date'] ? ['when' => $l['application_date'], 'what' => 'Loan application submitted', 'found' => true] : null,
            $l['approval_date'] ? ['when' => $l['approval_date'], 'what' => 'Loan approved', 'found' => true] : null,
            $l['disbursement_date'] ? ['when' => $l['disbursement_date'], 'what' => 'Loan disbursed', 'found' => true] : null,
            ['when' => $panel['journal']['entry_date'] ?? null, 'what' => 'Disbursement journal posted', 'found' => $panel['journal'] !== null],
        ]);

        return $panel;
    }

    // ================================================================
    // LOAN REPAYMENT TRACE
    // ================================================================
    public function traceRepayment(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT r.*, m.member_number, m.first_name, m.last_name, l.loan_number
            FROM loan_repayments r
            JOIN members m ON m.id = r.member_id
            JOIN loans l ON l.id = r.loan_id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $r = $stmt->fetch();
        if (!$r) { return $this->notFound('Loan Repayment', "#{$id}", 'Repayment not found.'); }

        $panel = $this->panel('Loan Repayment', $r['repayment_number']);
        $panel['facts'] = [
            ['label' => 'Member', 'value' => trim($r['first_name'] . ' ' . $r['last_name']) . " ({$r['member_number']})"],
            ['label' => 'Loan', 'value' => $r['loan_number']],
            ['label' => 'Payment Date', 'value' => $r['payment_date']],
            ['label' => 'Amount Paid', 'value' => $r['amount_paid']],
            ['label' => 'Principal Allocation', 'value' => $r['principal_paid']],
            ['label' => 'Interest Allocation', 'value' => $r['interest_paid']],
            ['label' => 'Penalty Allocation', 'value' => $r['penalty_paid']],
            ['label' => 'Savings Allocation', 'value' => $r['savings_paid']],
            ['label' => 'Payment Method', 'value' => $r['payment_method']],
            ['label' => 'Balance Before → After', 'value' => "{$r['balance_before']} → {$r['balance_after']}"],
            ['label' => 'Recorded By (user id)', 'value' => $r['received_by']],
        ];
        $panel['related'][] = [
            'label' => 'Related', 'items' => [
                ['label' => trim($r['first_name'] . ' ' . $r['last_name']), 'route' => 'investigation-member', 'id' => (int)$r['member_id']],
                ['label' => "Loan {$r['loan_number']}", 'route' => 'investigation-loan', 'id' => (int)$r['loan_id']],
            ],
        ];

        $this->attachJournal($panel, $r['journal_entry_id'] ? (int)$r['journal_entry_id'] : null, 'loan_repayments', $id);
        $this->attachActivityLogTextMatch($panel, $r['repayment_number']);

        $panel['timeline'] = [
            ['when' => $r['created_at'], 'what' => 'Repayment recorded', 'found' => true],
            ['when' => $panel['journal']['entry_date'] ?? null, 'what' => 'Repayment journal posted', 'found' => $panel['journal'] !== null],
        ];
        if ($panel['journal'] === null) {
            $panel['missing'][] = 'Repayment → Journal Entry: MISSING (see facts/timeline above for exact stored values used to reach this conclusion).';
        }

        return $panel;
    }

    // ================================================================
    // WITHDRAWAL TRACE
    // ================================================================
    public function traceWithdrawal(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT w.*, m.member_number, m.first_name, m.last_name, a.account_number, a.account_type
            FROM withdrawals w
            JOIN members m ON m.id = w.member_id
            LEFT JOIN member_savings_accounts a ON a.id = w.savings_account_id
            WHERE w.id = ?
        ");
        $stmt->execute([$id]);
        $w = $stmt->fetch();
        if (!$w) { return $this->notFound('Withdrawal', "#{$id}", 'Withdrawal not found.'); }

        $panel = $this->panel('Withdrawal', $w['withdrawal_number']);
        $panel['facts'] = [
            ['label' => 'Member', 'value' => trim($w['first_name'] . ' ' . $w['last_name']) . " ({$w['member_number']})"],
            ['label' => 'Savings Account', 'value' => $w['account_number'] ? "{$w['account_number']} ({$w['account_type']})" : 'none on record'],
            ['label' => 'Withdrawal Type', 'value' => $w['withdrawal_type']],
            ['label' => 'Withdrawal Date', 'value' => $w['withdrawal_date']],
            ['label' => 'Withdrawal Amount', 'value' => $w['withdrawal_amount']],
            ['label' => 'Retained Amount', 'value' => $w['retained_amount']],
            ['label' => 'Payment Method', 'value' => $w['payment_method']],
            ['label' => 'Processed By (user id)', 'value' => $w['processed_by']],
        ];
        $panel['related'][] = ['label' => 'Member', 'items' => [['label' => trim($w['first_name'] . ' ' . $w['last_name']), 'route' => 'investigation-member', 'id' => (int)$w['member_id']]]];

        $this->attachJournal($panel, $w['journal_entry_id'] ? (int)$w['journal_entry_id'] : null, 'withdrawals', $id);
        $this->attachActivityLogTextMatch($panel, $w['withdrawal_number']);

        $panel['timeline'] = [
            ['when' => $w['created_at'], 'what' => 'Withdrawal recorded', 'found' => true],
            ['when' => $panel['journal']['entry_date'] ?? null, 'what' => 'Withdrawal journal posted', 'found' => $panel['journal'] !== null],
        ];

        return $panel;
    }

    // ================================================================
    // FEE TRACE
    // ================================================================
    public function traceFee(int $id): array
    {
        $stmt = $this->db->prepare("
            SELECT f.*, m.member_number, m.first_name, m.last_name, ft.fee_name, ft.fee_type
            FROM member_fees f
            JOIN members m ON m.id = f.member_id
            JOIN fees ft ON ft.id = f.fee_id
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        $f = $stmt->fetch();
        if (!$f) { return $this->notFound('Fee Charge', "#{$id}", 'Fee charge not found.'); }

        $panel = $this->panel('Fee Charge', $f['reference_number'] ?: "#{$f['id']} ({$f['fee_name']})");
        $panel['facts'] = [
            ['label' => 'Member', 'value' => trim($f['first_name'] . ' ' . $f['last_name']) . " ({$f['member_number']})"],
            ['label' => 'Fee Type', 'value' => "{$f['fee_name']} ({$f['fee_type']})"],
            ['label' => 'Amount', 'value' => $f['amount']],
            ['label' => 'Status', 'value' => $f['status']],
            ['label' => 'Charged Date', 'value' => $f['charged_date']],
            ['label' => 'Paid Date', 'value' => $f['paid_date']],
            ['label' => 'Payment Method', 'value' => $f['payment_method']],
        ];
        $panel['related'][] = ['label' => 'Member', 'items' => [['label' => trim($f['first_name'] . ' ' . $f['last_name']), 'route' => 'investigation-member', 'id' => (int)$f['member_id']]]];

        $this->attachJournal($panel, $f['journal_entry_id'] ? (int)$f['journal_entry_id'] : null, 'fees', $id);
        if ($f['reference_number']) { $this->attachActivityLogTextMatch($panel, $f['reference_number']); }

        // Per SA-4 Section 14: a paid fee with no journal is worth an
        // explicit, precisely-worded warning (not "corruption").
        // Pending/waived/cancelled fees are NOT expected to have a
        // journal at all, so no warning is raised for those.
        if ($f['status'] === 'paid' && $panel['journal'] === null) {
            $panel['warnings'][] = 'Fee status is "Paid" but no journal linkage was found. Requires further review -- not automatically corruption.';
        } elseif ($f['status'] !== 'paid' && $panel['journal'] === null) {
            $panel['facts'][] = ['label' => 'Journal linkage', 'value' => "None expected -- status is \"{$f['status']}\", not \"paid\"."];
        }

        $panel['timeline'] = [
            ['when' => $f['charged_date'], 'what' => 'Fee charged', 'found' => true],
            ['when' => $f['paid_date'], 'what' => 'Fee paid', 'found' => $f['paid_date'] !== null],
            ['when' => $panel['journal']['entry_date'] ?? null, 'what' => 'Fee journal posted', 'found' => $panel['journal'] !== null],
        ];

        return $panel;
    }

    // ================================================================
    // JOURNAL-CENTRIC TRACE
    // ================================================================
    private const SOURCE_TABLES = [
        'savings' => ['table' => 'savings', 'ref' => 'receipt_number', 'route' => 'investigation-savings'],
        'loan_repayments' => ['table' => 'loan_repayments', 'ref' => 'repayment_number', 'route' => 'investigation-repayment'],
        'loans' => ['table' => 'loans', 'ref' => 'loan_number', 'route' => 'investigation-loan'],
        'withdrawals' => ['table' => 'withdrawals', 'ref' => 'withdrawal_number', 'route' => 'investigation-withdrawal'],
        'fees' => ['table' => 'member_fees', 'ref' => 'reference_number', 'route' => 'investigation-fee'],
    ];

    public function traceJournalEntry(int $id): array
    {
        $stmt = $this->db->prepare("SELECT * FROM journal_entries WHERE id = ?");
        $stmt->execute([$id]);
        $je = $stmt->fetch();
        if (!$je) { return $this->notFound('Journal Entry', "#{$id}", 'Journal entry not found.'); }

        $panel = $this->panel('Journal Entry', $je['entry_number']);
        $panel['facts'] = [
            ['label' => 'Entry Date', 'value' => $je['entry_date']],
            ['label' => 'Source Module', 'value' => $je['source_module']],
            ['label' => 'Source Reference ID', 'value' => $je['source_reference_id']],
            ['label' => 'Data Classification', 'value' => $je['data_classification']],
            ['label' => 'Description', 'value' => $je['description']],
            ['label' => 'Posted', 'value' => $je['posted'] ? 'Yes' : 'No'],
            ['label' => 'Reversed', 'value' => $je['reversed'] ? 'Yes' : 'No'],
        ];
        if ($je['data_classification'] !== 'live') {
            $panel['known_dummy'][] = "This journal entry is classified '{$je['data_classification']}' -- known pre-enforcement test data (Stage 24-28), not a confirmed defect.";
        }

        $lines = $this->db->prepare("SELECT jl.debit, jl.credit, jl.description, a.code, a.name FROM journal_lines jl JOIN accounts a ON a.id = jl.account_id WHERE jl.journal_entry_id = ? ORDER BY jl.id");
        $lines->execute([$id]);
        $lineRows = $lines->fetchAll();
        $panel['journal_lines'] = array_map(fn($l) => ['account' => "{$l['code']} — {$l['name']}", 'debit' => (float)$l['debit'], 'credit' => (float)$l['credit'], 'description' => $l['description']], $lineRows);
        $panel['journal'] = [
            'id' => (int)$je['id'],
            'total_debit' => array_sum(array_column($lineRows, 'debit')), 'total_credit' => array_sum(array_column($lineRows, 'credit')),
        ];
        $panel['journal']['difference'] = round($panel['journal']['total_debit'] - $panel['journal']['total_credit'], 2);

        $module = $je['source_module'];
        $srcId = $je['source_reference_id'];
        if ($module && isset(self::SOURCE_TABLES[$module]) && $srcId) {
            $cfg = self::SOURCE_TABLES[$module];
            $srcStmt = $this->db->prepare("SELECT * FROM `{$cfg['table']}` WHERE id = ?");
            $srcStmt->execute([$srcId]);
            $src = $srcStmt->fetch();
            if ($src) {
                $panel['facts'][] = ['label' => 'Source Record', 'value' => "{$cfg['table']} #{$srcId} ({$src[$cfg['ref']]}) -- found"];
                $panel['related'][] = ['label' => 'Source Record', 'items' => [['label' => $src[$cfg['ref']], 'route' => $cfg['route'], 'id' => (int)$srcId]]];
                if (isset($src['member_id'])) {
                    $panel['related'][] = ['label' => 'Member', 'items' => [['label' => "Member #{$src['member_id']}", 'route' => 'investigation-member', 'id' => (int)$src['member_id']]]];
                }
            } else {
                $panel['warnings'][] = "Source record not found: {$module} #{$srcId} referenced by this journal entry no longer exists in that table.";
                $panel['missing'][] = "{$cfg['table']} row with id={$srcId} (referenced by source_reference_id).";
                if ($je['data_classification'] !== 'live') {
                    $panel['known_dummy'][] = 'This orphaned reference matches the already-documented category from Stage 19D "orphan journal forensics" -- a prior manual test-data cleanup operation, not a new incident.';
                }
            }
        } elseif ($module && $srcId) {
            $panel['missing'][] = "Source module '{$module}' is not one this investigation tool resolves automatically (recognized modules: " . implode(', ', array_keys(self::SOURCE_TABLES)) . ').';
        } else {
            $panel['facts'][] = ['label' => 'Source Record', 'value' => 'No source_module/source_reference_id recorded on this journal entry.'];
        }

        $this->attachJournalAudit($panel, $id);

        return $panel;
    }

    // ================================================================
    // ORPHAN & COVERAGE-GAP LISTINGS (row-level drill-down for SA-3's
    // aggregate counts)
    // ================================================================

    /** @return array{rows: array, total: int} */
    public function listOrphanJournalReferences(int $limit = 25, int $offset = 0): array
    {
        $rows = [];
        $total = 0;
        foreach (self::SOURCE_TABLES as $module => $cfg) {
            $stmt = $this->db->prepare("
                SELECT je.id, je.entry_number, je.entry_date, je.source_reference_id, je.data_classification
                FROM journal_entries je
                WHERE je.source_module = ? AND je.source_reference_id IS NOT NULL
                  AND NOT EXISTS (SELECT 1 FROM `{$cfg['table']}` t WHERE t.id = je.source_reference_id)
                ORDER BY je.id
            ");
            $stmt->execute([$module]);
            foreach ($stmt->fetchAll() as $r) {
                $rows[] = [
                    'journal_id' => (int)$r['id'], 'entry_number' => $r['entry_number'], 'entry_date' => $r['entry_date'],
                    'source_module' => $module, 'source_reference_id' => (int)$r['source_reference_id'],
                    'classification' => $r['data_classification'],
                    'state' => $r['data_classification'] === 'live' ? 'SOURCE MISSING' : 'KNOWN DUMMY DATA',
                ];
            }
        }
        $total = count($rows);
        return ['rows' => array_slice($rows, $offset, $limit), 'total' => $total];
    }

    /** @return array{rows: array, total: int} */
    public function listCoverageGaps(string $type, int $limit = 25, int $offset = 0): array
    {
        $configs = [
            'loan_repayments' => "SELECT lr.id, lr.repayment_number AS reference, lr.payment_date AS date, lr.amount_paid AS amount, lr.created_at, m.first_name, m.last_name
                FROM loan_repayments lr JOIN members m ON m.id = lr.member_id
                WHERE lr.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='loan_repayments' AND je.source_reference_id=lr.id)
                ORDER BY lr.payment_date, lr.id",
            'savings' => "SELECT s.id, s.receipt_number AS reference, s.transaction_date AS date, COALESCE(s.credit,s.debit) AS amount, s.created_at, m.first_name, m.last_name
                FROM savings s JOIN members m ON m.id = s.member_id
                WHERE s.journal_entry_id IS NULL AND NOT EXISTS (SELECT 1 FROM journal_entries je WHERE je.source_module='savings' AND je.source_reference_id=s.id)
                ORDER BY s.transaction_date, s.id",
        ];
        if (!isset($configs[$type])) { return ['rows' => [], 'total' => 0]; }

        $all = $this->db->query($configs[$type])->fetchAll();
        $rows = array_map(fn($r) => [
            'id' => (int)$r['id'], 'reference' => $r['reference'], 'date' => $r['date'], 'amount' => (float)$r['amount'],
            'created_at' => $r['created_at'], 'member' => trim($r['first_name'] . ' ' . $r['last_name']),
            'route' => $type === 'savings' ? 'investigation-savings' : 'investigation-repayment',
        ], $all);
        return ['rows' => array_slice($rows, $offset, $limit), 'total' => count($rows)];
    }
}
