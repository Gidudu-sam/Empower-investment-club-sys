<?php
$base = APP_URL . '/index.php';
$statusBadge = [
    'executed' => ['bg-success-subtle text-success', 'bi-check-circle-fill', 'SUCCESSFUL'],
    'failed' => ['bg-danger-subtle text-danger', 'bi-x-circle-fill', 'FAILED'],
    'confirmed' => ['bg-info-subtle text-info', 'bi-hourglass-split', 'CONFIRMED (not yet executed)'],
    'prepared' => ['bg-secondary-subtle text-secondary', 'bi-hourglass', 'PREPARED (not yet executed)'],
    'cancelled' => ['bg-secondary-subtle text-secondary', 'bi-dash-circle-fill', 'CANCELLED'],
];
$badge = $statusBadge[$correction['status']] ?? ['bg-secondary-subtle text-secondary', 'bi-question-circle', strtoupper($correction['status'])];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-shield-check me-2 text-info"></i>Correction Result</h1>
    </div>
    <a href="<?= $base ?>?page=corrections" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Corrections</a>
</div>

<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center <?= $badge[0] ?>">
        <h5 class="mb-0 fw-bold"><i class="bi <?= $badge[1] ?> me-2"></i>CORRECTION <?= $badge[2] ?></h5>
        <span class="fw-bold"><?= htmlspecialchars($correction['correction_number']) ?></span>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-sm mb-0">
            <tr><th class="text-muted small fw-normal" style="width:35%">Correction</th><td class="fw-semibold"><?= htmlspecialchars($correction['correction_number']) ?></td></tr>
            <tr><th class="text-muted small fw-normal">Target</th><td><?= htmlspecialchars($correction['target_entity_type']) ?> <?= htmlspecialchars((string)$correction['target_reference']) ?></td></tr>
            <tr><th class="text-muted small fw-normal">Type</th><td><?= htmlspecialchars($correction['correction_type']) ?></td></tr>
            <tr><th class="text-muted small fw-normal">Reason</th><td><?= nl2br(htmlspecialchars($correction['reason'])) ?></td></tr>
            <?php if ($correction['status'] === 'executed'): ?>
            <tr><th class="text-muted small fw-normal">Original Journal</th><td><?= htmlspecialchars((string)$correction['target_reference']) ?> (remains visible, unmodified)</td></tr>
            <tr><th class="text-muted small fw-normal">Reversal Journal</th><td class="fw-semibold text-success"><?= htmlspecialchars((string)$correction['resulting_journal_entry_number']) ?></td></tr>
            <?php if ($resultingJournal && $resultingJournal['journal']): ?>
            <tr><th class="text-muted small fw-normal">Accounting</th><td>Debit <?= number_format($resultingJournal['journal']['total_debit'], 2) ?> / Credit <?= number_format($resultingJournal['journal']['total_credit'], 2) ?></td></tr>
            <?php endif; ?>
            <tr><th class="text-muted small fw-normal">Executed By</th><td><?= htmlspecialchars((string)$correction['executed_by']) ?></td></tr>
            <tr><th class="text-muted small fw-normal">Executed At</th><td><?= htmlspecialchars((string)$correction['executed_at']) ?></td></tr>
            <?php elseif ($correction['status'] === 'failed'): ?>
            <tr><th class="text-muted small fw-normal">Failure Reason</th><td class="text-danger"><?= htmlspecialchars((string)$correction['failure_reason']) ?></td></tr>
            <?php endif; ?>
            <tr><th class="text-muted small fw-normal">Status</th><td><span class="fw-bold"><?= $badge[2] ?></span></td></tr>
        </table>
        </div>

        <?php if ($resultingJournal && !empty($resultingJournal['journal_lines'])): ?>
        <hr>
        <h6 class="fw-semibold">Reversal Journal Lines</h6>
        <div class="table-responsive">
            <table class="table table-sm">
            <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
            <tbody>
                <?php foreach ($resultingJournal['journal_lines'] as $l): ?>
                <tr><td class="small"><?= htmlspecialchars($l['account']) ?></td><td class="text-end"><?= number_format($l['debit'], 2) ?></td><td class="text-end"><?= number_format($l['credit'], 2) ?></td></tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
        <a href="<?= $base ?>?page=investigation-journal&id=<?= $correction['resulting_journal_entry_id'] ?>" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-search"></i> Investigate Reversal Journal
        </a>
        <?php endif; ?>
    </div>
</div>

<div class="alert alert-secondary mt-3 small mb-0">
    <i class="bi bi-info-circle me-1"></i>
    This result is permanent and re-viewable at any time at this URL. The original journal entry remains historically visible and was never edited or deleted.
</div>
