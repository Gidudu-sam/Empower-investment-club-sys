<?php
$base = APP_URL . '/index.php';
$evidence = $eval['evidence'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-800"><i class="bi bi-shield-exclamation me-2 text-danger"></i>Review &amp; Confirm — <?= htmlspecialchars($correction['correction_number']) ?></h1>
        <p class="text-muted mb-0 small">Step 2 of 2 — this is the last step before any accounting mutation occurs.</p>
    </div>
    <a href="<?= $base ?>?page=corrections" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if (!$eval['eligible']): ?>
<div class="alert alert-danger">
    <h6 class="fw-bold"><i class="bi bi-x-circle-fill me-1"></i>This correction can no longer proceed</h6>
    <ul class="mb-0 small">
        <?php foreach ($eval['blockers'] as $b): ?>
        <li><?= htmlspecialchars($b) ?></li>
        <?php endforeach; ?>
    </ul>
    <p class="small mb-0 mt-2">The target's state changed since this correction was prepared, or it no longer qualifies. Cancel this correction and re-investigate.</p>
</div>
<form method="POST" action="<?= $base ?>?page=correction-cancel" class="mt-2">
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
    <input type="hidden" name="id" value="<?= $correction['id'] ?>">
    <button type="submit" class="btn btn-outline-secondary btn-sm">Cancel This Correction</button>
</form>
<?php else: ?>
<?php $je = $evidence['journal']; ?>

<div class="row g-3">
    <div class="col-lg-7">
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">WHAT WILL CHANGE</h6></div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                    <tr><th class="text-muted small fw-normal" style="width:40%">Affected Record</th><td>Journal Entry <?= htmlspecialchars($je['entry_number']) ?></td></tr>
                    <tr><th class="text-muted small fw-normal">Correction Type</th><td><?= htmlspecialchars($correction['correction_type']) ?> — a new compensating (mirror-image) journal entry will be created</td></tr>
                    <tr><th class="text-muted small fw-normal">Accounting Effect</th><td>Total Debit <?= number_format($evidence['total_debit'], 2) ?> / Total Credit <?= number_format($evidence['total_credit'], 2) ?> will be exactly reversed on a new entry dated today</td></tr>
                    <tr><th class="text-muted small fw-normal">Journal To Be Reversed</th><td><?= htmlspecialchars($je['entry_number']) ?> (remains permanently visible, never edited or deleted)</td></tr>
                    <tr><th class="text-muted small fw-normal">Who Is Executing</th><td><?= htmlspecialchars(Session::get('user_name', 'System Administrator')) ?></td></tr>
                </table>
                </div>
            </div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">WHY</h6></div>
            <div class="card-body"><p class="mb-0 small"><?= nl2br(htmlspecialchars($correction['reason'])) ?></p></div>
        </div>
        <div class="card mb-3">
            <div class="card-header"><h6 class="mb-0 fw-semibold">Current Journal Lines (will be mirrored, debit↔credit swapped)</h6></div>
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead><tr><th>Account</th><th class="text-end">Current Debit</th><th class="text-end">Current Credit</th></tr></thead>
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
        <div class="card border-danger">
            <div class="card-header bg-danger-subtle text-danger fw-semibold"><i class="bi bi-exclamation-triangle-fill me-1"></i>Final Confirmation</div>
            <div class="card-body">
                <p class="small text-muted">This action is not a GET request and cannot be triggered accidentally. Once executed, it cannot be undone through this interface — the resulting reversal would itself need its own separate, investigated correction.</p>
                <form method="POST" action="<?= $base ?>?page=correction-execute">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $correction['id'] ?>">
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="confirm" value="1" id="confirmCheck" required>
                        <label class="form-check-label small" for="confirmCheck">
                            I have reviewed the evidence above and confirm this correction should be executed.
                        </label>
                    </div>
                    <button type="submit" class="btn btn-danger w-100"><i class="bi bi-check2-circle me-1"></i>Confirm &amp; Execute Correction</button>
                </form>
                <form method="POST" action="<?= $base ?>?page=correction-cancel" class="mt-2">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" value="<?= $correction['id'] ?>">
                    <button type="submit" class="btn btn-outline-secondary w-100 btn-sm">Cancel Instead</button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
