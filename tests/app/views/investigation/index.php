<?php
$base = APP_URL . '/index.php';
$routeMap = [
    'Member' => 'investigation-member', 'Savings Transaction' => 'investigation-savings',
    'Loan' => 'investigation-loan', 'Loan Repayment' => 'investigation-repayment',
    'Withdrawal' => 'investigation-withdrawal', 'Fee Charge' => 'investigation-fee',
    'Journal Entry' => 'investigation-journal',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-search me-2 text-info"></i>Transaction Investigation
        </h1>
        <p class="text-muted mb-0 small">Read-only forensic traceability. Search a member, transaction, loan, repayment, withdrawal, fee, or journal entry.</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=investigation-orphans" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-diagram-3 me-1"></i>Orphan Journal References
        </a>
        <a href="<?= $base ?>?page=investigation-coverage" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-list-check me-1"></i>Coverage Gaps
        </a>
    </div>
</div>

<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="<?= $base ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="investigation">
            <div class="col-md-3">
                <label class="form-label small fw-semibold">Search In</label>
                <select name="type" class="form-select">
                    <option value="">All types</option>
                    <option value="member" <?= $type === 'member' ? 'selected' : '' ?>>Member</option>
                    <option value="savings" <?= $type === 'savings' ? 'selected' : '' ?>>Savings Transaction</option>
                    <option value="loan" <?= $type === 'loan' ? 'selected' : '' ?>>Loan</option>
                    <option value="repayment" <?= $type === 'repayment' ? 'selected' : '' ?>>Loan Repayment</option>
                    <option value="withdrawal" <?= $type === 'withdrawal' ? 'selected' : '' ?>>Withdrawal</option>
                    <option value="fee" <?= $type === 'fee' ? 'selected' : '' ?>>Fee Charge</option>
                    <option value="journal" <?= $type === 'journal' ? 'selected' : '' ?>>Journal Entry</option>
                </select>
            </div>
            <div class="col-md-7">
                <label class="form-label small fw-semibold">Search Term</label>
                <input type="text" name="q" class="form-control" value="<?= htmlspecialchars($term) ?>"
                       placeholder="Member number/name, receipt/loan/repayment/withdrawal/journal reference...">
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
            </div>
        </form>
    </div>
</div>

<?php if ($term !== ''): ?>
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">Results</h6>
        <span class="badge bg-secondary-subtle text-secondary"><?= count($results) ?> found</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>Type</th><th>Reference</th><th>Member</th><th>Date</th>
                    <th class="text-end">Amount</th><th>Status</th><th>Journal</th><th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td><span class="badge bg-info-subtle text-info"><?= htmlspecialchars($r['entity_type']) ?></span></td>
                    <td class="fw-semibold"><?= htmlspecialchars($r['reference']) ?></td>
                    <td><?= htmlspecialchars($r['member'] ?? '—') ?></td>
                    <td class="small"><?= htmlspecialchars((string)($r['date'] ?? '—')) ?></td>
                    <td class="text-end"><?= $r['amount'] !== null ? number_format((float)$r['amount']) : '—' ?></td>
                    <td class="small"><?= htmlspecialchars((string)($r['status'] ?? '—')) ?></td>
                    <td>
                        <?php if ($r['journal_status'] === 'Linked'): ?>
                            <span class="badge bg-success-subtle text-success">Linked</span>
                        <?php elseif ($r['journal_status'] === 'Not linked'): ?>
                            <span class="badge bg-warning-subtle text-warning">Not linked</span>
                        <?php else: ?>
                            <span class="text-muted small">n/a</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end">
                        <?php if (isset($routeMap[$r['entity_type']])): ?>
                        <a href="<?= $base ?>?page=<?= $routeMap[$r['entity_type']] ?>&id=<?= $r['id'] ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-eye"></i> Trace
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($results)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No matching records.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Read-only. Investigating a record never modifies it. If a genuine defect is found, it requires the controlled correction/reversal workflow (a later stage) — nothing here repairs, reverses, or posts anything.
</div>
