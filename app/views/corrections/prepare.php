<?php
$base = APP_URL . '/index.php';
$evidence = $eval['evidence'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Prepare Correction</h1>
        <p class="text-muted mb-0 small">Step 1 of 2 — review evidence and state your reason. Nothing is changed yet.</p>
    </div>
    <a href="<?= $base ?>?page=investigation-journal&id=<?= $journalId ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back to Investigation</a>
</div>

<?php if (!$evidence): ?>
<div class="alert alert-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>Journal entry #<?= $journalId ?> not found.</div>
<?php else: ?>
<?php $je = $evidence['journal']; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Target: Journal Entry <?= htmlspecialchars($je['entry_number']) ?></h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <tr><th class="text-muted small fw-normal" style="width:40%">Entry Date</th><td><?= htmlspecialchars($je['entry_date']) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Source Module</th><td><?= htmlspecialchars((string)$je['source_module']) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Classification</th><td><span class="badge bg-secondary-subtle text-secondary"><?= htmlspecialchars($je['data_classification']) ?></span></td></tr>
                    <tr><th class="text-muted small fw-normal">Total Debit</th><td><?= number_format($evidence['total_debit'], 2) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Total Credit</th><td><?= number_format($evidence['total_credit'], 2) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Description</th><td><?= htmlspecialchars((string)$je['description']) ?></td></tr>
                </table>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Journal Lines</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Account</th><th class="text-end">Debit</th><th class="text-end">Credit</th></tr></thead>
                    <tbody>
                        <?php foreach ($evidence['lines'] as $l): ?>
                        <tr><td class="small"><?= htmlspecialchars($l['code'] . ' — ' . $l['name']) ?></td><td class="text-end"><?= number_format($l['debit'], 2) ?></td><td class="text-end"><?= number_format($l['credit'], 2) ?></td></tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <?php if (!$eval['eligible']): ?>
        <div class="alert alert-danger">
            <h6 class="fw-bold"><i class="bi bi-x-circle-fill me-1"></i>Correction Blocked</h6>
            <ul class="mb-0 small">
                <?php foreach ($eval['blockers'] as $b): ?>
                <li><?= htmlspecialchars($b) ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php else: ?>
        <div class="card border-warning">
            <div class="card-header bg-warning-subtle text-warning fw-semibold">Reverse Journal Entry</div>
            <div class="card-body">
                <form method="POST" action="<?= $base ?>?page=correction-store">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="journal_id" value="<?= $journalId ?>">
                    <p class="small text-muted">This will use the existing canonical reversal engine (<code>JournalService::reverse()</code>) to create a mirror-image compensating entry. The original entry is never edited or deleted.</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold small">Reason (required, minimum 20 characters)</label>
                        <textarea name="reason" class="form-control" rows="4" minlength="20" required
                            placeholder="Describe the specific evidence reviewed (e.g. via SA-4 Transaction Investigation) and why this journal entry must be reversed."></textarea>
                    </div>
                    <button type="submit" class="btn btn-warning w-100"><i class="bi bi-arrow-right-circle me-1"></i>Prepare Correction &amp; Continue to Review</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
