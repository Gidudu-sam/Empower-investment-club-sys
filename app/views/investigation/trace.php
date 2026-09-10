<?php
$base = APP_URL . '/index.php';
$routeMap = [
    'investigation-member' => 'investigation-member', 'investigation-savings' => 'investigation-savings',
    'investigation-loan' => 'investigation-loan', 'investigation-repayment' => 'investigation-repayment',
    'investigation-withdrawal' => 'investigation-withdrawal', 'investigation-fee' => 'investigation-fee',
    'investigation-journal' => 'investigation-journal',
];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800">
            <i class="bi bi-diagram-3 me-2 text-info"></i><?= htmlspecialchars($panel['entity_type']) ?>: <?= htmlspecialchars((string)$panel['reference']) ?>
        </h1>
        <p class="text-muted mb-0 small">Forensic evidence panel — read-only.</p>
    </div>
    <a href="<?= $base ?>?page=investigation" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Search
    </a>
</div>

<?php if (!empty($panel['missing']) && empty($panel['facts'])): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($panel['missing'][0]) ?></div>
<?php else: ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-check2-square me-1"></i>Facts</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <?php foreach ($panel['facts'] as $f): ?>
                    <tr><th class="text-muted small fw-normal" style="width:40%"><?= htmlspecialchars($f['label']) ?></th><td><?= htmlspecialchars((string)($f['value'] ?? '—')) ?></td></tr>
                    <?php endforeach; ?>
                </table>
                </div>
            </div>
        </div>

        <?php if ($panel['journal'] !== null): ?>
        <div class="card mb-3">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-semibold"><i class="bi bi-journal-text me-1"></i>Linked Journal Entry</h6>
                <div class="d-flex gap-2">
                    <?php if (!empty($panel['journal']['entry_number'])): ?>
                    <a href="<?= $base ?>?page=investigation-journal&id=<?= $panel['journal']['id'] ?>" class="btn btn-sm btn-outline-primary">View Journal</a>
                    <?php endif; ?>
                    <?php if (!empty($panel['journal']['id'])): ?>
                    <a href="<?= $base ?>?page=correction-prepare&journal_id=<?= $panel['journal']['id'] ?>" class="btn btn-sm btn-outline-danger">
                        <i class="bi bi-shield-exclamation"></i> Prepare Correction
                    </a>
                    <?php endif; ?>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($panel['journal']['entry_number'])): ?>
                <div class="row small mb-2">
                    <div class="col-6"><strong>Entry:</strong> <?= htmlspecialchars($panel['journal']['entry_number']) ?></div>
                    <div class="col-6"><strong>Date:</strong> <?= htmlspecialchars((string)$panel['journal']['entry_date']) ?></div>
                    <div class="col-6"><strong>Classification:</strong> <?= htmlspecialchars($panel['journal']['data_classification']) ?></div>
                    <div class="col-6"><strong>Posted:</strong> <?= $panel['journal']['posted'] ? 'Yes' : 'No' ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($panel['journal_lines'])): ?>
                <div class="table-responsive">
                    <table class="table table-sm">
                    <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                        <?php foreach ($panel['journal_lines'] as $l): ?>
                        <tr><td class="small"><?= htmlspecialchars($l['account']) ?></td><td class="text-end"><?= number_format($l['debit'], 2) ?></td><td class="text-end"><?= number_format($l['credit'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fw-semibold"><td>Total</td><td class="text-end"><?= number_format($panel['journal']['total_debit'], 2) ?></td><td class="text-end"><?= number_format($panel['journal']['total_credit'], 2) ?></td></tr>
                        <?php if (abs($panel['journal']['difference']) > 0.01): ?>
                        <tr class="text-danger fw-semibold"><td colspan="3">Difference: <?= number_format($panel['journal']['difference'], 2) ?></td></tr>
                        <?php endif; ?>
                    </tfoot>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($panel['timeline'])): ?>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-clock-history me-1"></i>Timeline</h6></div>
            <div class="card-body">
                <?php foreach ($panel['timeline'] as $t): if (!$t) continue; ?>
                <div class="d-flex align-items-center gap-2 mb-2">
                    <i class="bi <?= $t['found'] ? 'bi-check-circle-fill text-success' : 'bi-x-circle-fill text-danger' ?>"></i>
                    <div>
                        <div class="small fw-semibold"><?= htmlspecialchars($t['what']) ?></div>
                        <div class="small text-muted"><?= $t['found'] ? htmlspecialchars((string)$t['when']) : 'NOT FOUND' ?></div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if (!empty($panel['audit'])): ?>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-shield-check me-1"></i>Audit Trail</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead class="table-light"><tr><th>When</th><th>Actor</th><th>Action</th><th>Detail</th><th>Source</th></tr></thead>
                    <tbody>
                        <?php foreach ($panel['audit'] as $a): ?>
                        <tr>
                            <td class="small"><?= htmlspecialchars((string)$a['when']) ?></td>
                            <td class="small"><?= htmlspecialchars((string)$a['actor']) ?></td>
                            <td class="small"><?= htmlspecialchars((string)$a['action']) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string)($a['reason'] ?? '')) ?></td>
                            <td class="small text-muted"><?= htmlspecialchars((string)$a['source']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <div class="col-lg-5">
        <?php if (!empty($panel['warnings'])): ?>
        <div class="card mb-3 border-warning">
            <div class="card-header bg-warning-subtle text-warning fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Warnings</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($panel['warnings'] as $w): ?>
                <li class="list-group-item small"><?= htmlspecialchars($w) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($panel['missing'])): ?>
        <div class="card mb-3 border-secondary">
            <div class="card-header bg-secondary-subtle text-secondary fw-semibold"><i class="bi bi-dash-circle-fill me-1"></i>Missing Data</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($panel['missing'] as $m): ?>
                <li class="list-group-item small"><?= htmlspecialchars($m) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php if (!empty($panel['known_dummy'])): ?>
        <div class="card mb-3 border-info">
            <div class="card-header bg-info-subtle text-info fw-semibold"><i class="bi bi-info-circle-fill me-1"></i>Known Historical/Dummy</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($panel['known_dummy'] as $k): ?>
                <li class="list-group-item small"><?= htmlspecialchars($k) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>

        <?php foreach ($panel['related'] as $group): ?>
        <div class="card mb-3">
            <div class="card-header fw-semibold small"><?= htmlspecialchars($group['label']) ?></div>
            <ul class="list-group list-group-flush">
                <?php foreach ($group['items'] as $item): ?>
                <li class="list-group-item small d-flex justify-content-between align-items-center">
                    <span><?= htmlspecialchars($item['label']) ?></span>
                    <?php if (!empty($item['route']) && isset($routeMap[$item['route']])): ?>
                    <a href="<?= $base ?>?page=<?= $routeMap[$item['route']] ?>&id=<?= $item['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                    <?php endif; ?>
                </li>
                <?php endforeach; ?>
                <?php if (empty($group['items'])): ?>
                <li class="list-group-item small text-muted">None</li>
                <?php endif; ?>
            </ul>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    Read-only evidence panel. If a genuine defect is confirmed here, it requires the controlled correction/reversal workflow (a later stage) — nothing on this page repairs, reverses, or posts anything.
</div>
