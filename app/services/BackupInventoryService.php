<?php
/**
 * BackupInventoryService — SA-6 (Database Recovery & Restore, 2026-09).
 *
 * READ-ONLY. Scans the two known, already-`.htaccess`-protected backup
 * locations (root backups/ and database/backups/ -- both blocked from
 * direct HTTP access since SA-2) and performs static file inspection
 * only: filename, size, timestamp, sha256 checksum, and a text-level
 * structural check for expected SQL shapes. It never executes SQL from a
 * candidate file and never connects to any database to "test" a backup
 * -- that live restore step is DatabaseRecoveryService's job, gated much
 * more strictly.
 *
 * Every backup is identified by a stable `id` (a short sha256 hash of its
 * absolute path) rather than by the raw path itself -- this is the only
 * handle the controller/request layer ever sees or accepts, so a browser
 * can never submit an arbitrary filesystem path (SA-6 brief Section 17/18).
 */
class BackupInventoryService
{
    /** Tables this application's live models actually depend on (same
     *  curated list SystemIntegrityService uses) -- used only to judge
     *  whether a backup's dump plausibly contains the expected schema,
     *  never to decide what's "required" to exist in production. */
    private const CRITICAL_TABLES = [
        'members', 'member_savings_accounts', 'savings', 'loans', 'loan_repayments',
        'withdrawals', 'member_fees', 'journal_entries', 'journal_lines',
        'accounts', 'users', 'roles', 'financial_years', 'accounting_periods',
    ];

    private array $backupDirs;

    public function __construct()
    {
        $this->backupDirs = [
            'backups' => ROOT_PATH . '/backups',
            'database/backups' => ROOT_PATH . '/database/backups',
        ];
    }

    /** @return array<int, array> */
    public function scan(): array
    {
        $results = [];
        foreach ($this->backupDirs as $label => $dir) {
            if (!is_dir($dir)) { continue; }
            foreach (glob($dir . '/*.sql') as $path) {
                $results[] = $this->inspect($path, $label);
            }
        }
        // Newest first -- the most likely candidate recovery point.
        usort($results, fn($a, $b) => $b['modified_at'] <=> $a['modified_at']);
        return $results;
    }

    public function getById(string $id): ?array
    {
        foreach ($this->scan() as $row) {
            if ($row['id'] === $id) { return $row; }
        }
        return null;
    }

    private function makeId(string $path): string
    {
        return substr(hash('sha256', $path), 0, 16);
    }

    private function inspect(string $path, string $directoryLabel): array
    {
        $filename = basename($path);
        $size = filesize($path);
        $mtime = filemtime($path);

        if ($size === 0) {
            return $this->row($path, $filename, $directoryLabel, $size, $mtime, null, 'INVALID', ['File is empty (0 bytes).'], [], self::CRITICAL_TABLES);
        }

        // Reading the whole file is safe here: every candidate backup in
        // this project is well under 1MB. A future much-larger dump would
        // need a streaming scan instead -- noted as a scaling limit, not
        // implemented speculatively.
        $content = file_get_contents($path);
        $checksum = hash('sha256', $content);

        $notes = [];
        $hasCreateTable = (bool)preg_match('/CREATE TABLE/i', $content);
        $hasInsert = (bool)preg_match('/INSERT INTO/i', $content);
        $hasDropDatabase = (bool)preg_match('/DROP DATABASE/i', $content);

        $foundTables = [];
        $missingTables = [];
        foreach (self::CRITICAL_TABLES as $t) {
            if (preg_match('/`' . preg_quote($t, '/') . '`/', $content)) {
                $foundTables[] = $t;
            } else {
                $missingTables[] = $t;
            }
        }

        if (!$hasCreateTable) { $notes[] = 'No CREATE TABLE statement found -- likely a header-only or truncated dump.'; }
        if ($hasDropDatabase) { $notes[] = 'Contains a DROP DATABASE statement -- flagged for manual review before any restore.'; }
        // A dump missing INSERT statements is not necessarily invalid --
        // it may legitimately be a schema-only export -- reported as a
        // note, not an automatic failure.
        if (!$hasInsert) { $notes[] = 'No INSERT INTO statement found -- appears to be schema-only (no data rows), or empty tables.'; }

        // Crude truncation heuristic: a well-formed dump's last
        // non-blank line is either a statement terminator/directive, or
        // mysqldump's own footer comment ("-- Dump completed on ..."),
        // which does not end in ';' or '*/' itself -- checking whether
        // the line STARTS with a comment marker (not whether the whole
        // trimmed file ends in one) correctly recognizes that footer.
        $trimmed = rtrim($content);
        $lines = preg_split('/\r\n|\r|\n/', $trimmed);
        $lastLine = trim(end($lines) ?: '');
        $looksTerminated = str_ends_with($trimmed, ';') || str_ends_with($trimmed, '*/') || str_starts_with($lastLine, '--');

        if (!$hasCreateTable) {
            $status = 'INVALID';
        } elseif (count($missingTables) === count(self::CRITICAL_TABLES)) {
            $status = 'INVALID';
            $notes[] = 'None of the expected critical tables were found anywhere in this dump.';
        } elseif (!$looksTerminated) {
            $status = 'UNKNOWN';
            $notes[] = 'File does not end in a recognizable statement terminator -- possible truncation, cannot be confirmed by static inspection alone.';
        } else {
            $status = 'STRUCTURALLY_VALID_UNTESTED';
        }

        return $this->row($path, $filename, $directoryLabel, $size, $mtime, $checksum, $status, $notes, $foundTables, $missingTables);
    }

    private function row(string $path, string $filename, string $dirLabel, int $size, int $mtime, ?string $checksum, string $status, array $notes, array $found, array $missing): array
    {
        return [
            'id' => $this->makeId($path),
            'filename' => $filename,
            'directory' => $dirLabel,
            // 'path' is intentionally the only field carrying the real
            // filesystem location -- getById() is the sole consumer that
            // ever reads it back out, and it is never rendered/sent to
            // the browser as an editable value.
            'path' => $path,
            'size_bytes' => $size,
            'modified_at' => date('Y-m-d H:i:s', $mtime),
            'sha256' => $checksum,
            'structural_status' => $status,
            'structural_notes' => $notes,
            'critical_tables_found' => $found,
            'critical_tables_missing' => $missing,
        ];
    }
}
