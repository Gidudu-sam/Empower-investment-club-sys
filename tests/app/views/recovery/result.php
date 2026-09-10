<?php
$base = APP_URL . '/index.php';
$statusBadge = [
    'verified' => 'bg-success-subtle text-success', 'failed' => 'bg-danger-subtle text-danger',
    'rejected' => 'bg-secondary-subtle text-secondary', 'restore_tested' => 'bg-info-subtle text-info',
];
$integrityBadge = [
    'PASS' => 'bg-success-subtle text-success', 'WARNING' => 'bg-warning-subtle text-warning',
    'ERROR' => 'bg-danger-subtle text-danger', 'UNAVAILABLE' => 'bg-secondary-subtle text-secondary',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-clipboard-check me-2 text-info"></i>Recovery Verification #<?= $recovery['id'] ?></h1>
        <p class="text-muted mb-0 small"><?= htmlspecialchars($recovery['backup_filename']) ?></p>
    </div>
    <a href="<?= $base ?>?page=recovery" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<div class="alert <?= $statusBadge[$recovery['status']] ?? 'alert-secondary' ?> mb-4">
    <strong>Status: <?= strtoupper($recovery['status']) ?></strong>
    — Target database: <code><?= htmlspecialchars($recovery['target_database']) ?></code> (isolated, never production)
    <?php if ($recovery['error_message']): ?>
    <div class="mt-2 small"><?= htmlspecialchars($recovery['error_message']) ?></div>
    <?php endif; ?>
</div>

<?php if ($summary): ?>
<div class="row g-3">
    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Schema Verification</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <tr><th class="text-muted small fw-normal" style="width:50%">Total Tables</th><td><?= $summary['schema']['table_count'] ?? '—' ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Critical Tables Present</th><td><?= count($summary['schema']['critical_tables_present'] ?? []) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Critical Tables Missing</th><td class="<?= !empty($summary['schema']['critical_tables_missing']) ? 'text-danger fw-semibold' : '' ?>"><?= empty($summary['schema']['critical_tables_missing']) ? 'None' : implode(', ', $summary['schema']['critical_tables_missing']) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Foreign Keys</th><td><?= $summary['schema']['foreign_key_count'] ?? '—' ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Tables with Primary Key</th><td><?= $summary['schema']['tables_with_primary_key'] ?? '—' ?></td></tr>
                </table>
                </div>
            </div>
        </div>

        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Recovery Point</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <?php foreach (($summary['recovery_point'] ?? []) as $label => $date): ?>
                    <tr><th class="text-muted small fw-normal" style="width:50%"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $label))) ?></th><td><?= htmlspecialchars((string)($date ?? 'n/a')) ?></td></tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Data Metrics</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <?php foreach (($summary['data'] ?? []) as $label => $value): ?>
                    <tr><th class="text-muted small fw-normal" style="width:50%"><?= htmlspecialchars(ucwords(str_replace('_', ' ', $label))) ?></th><td><?= is_bool($value) ? ($value ? 'Yes' : 'No') : htmlspecialchars((string)$value) ?></td></tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        </div>

        <?php if (!empty($summary['integrity']['ok'])): ?>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">SystemIntegrityService Results (run against this isolated DB)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>Check</th><th>Status</th><th>Summary</th></tr></thead>
                    <tbody>
                        <?php foreach ($summary['integrity']['results'] as $r): ?>
                        <tr>
                            <td class="small fw-semibold"><?= htmlspecialchars($r['title']) ?></td>
                            <td><span class="badge <?= $integrityBadge[$r['status']] ?? '' ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                            <td class="small text-muted"><?= htmlspecialchars($r['message']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php else: ?>
        <div class="alert alert-secondary small">Integrity diagnostics could not be run: <?= htmlspecialchars($summary['integrity']['error'] ?? 'unknown reason') ?></div>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-secondary mt-1 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Differences from current production counts do not automatically mean corruption — a backup is a snapshot of a historical recovery point and will legitimately differ from data recorded after it was created. WARNING-level integrity findings on dummy-classified data are expected and already documented (see project memory, Stage 24-28 / Stage 19D).
</div>
<?php else: ?>
<div class="alert alert-secondary">No verification summary is available for this recovery record (restore may have failed before verification ran).</div>
<?php if ($recovery['restore_output']): ?>
<pre class="bg-dark text-light p-3 small"><?= htmlspecialchars($recovery['restore_output']) ?></pre>
<?php endif; ?>
<?php endif; ?>
