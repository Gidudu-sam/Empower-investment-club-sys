<?php
$base = APP_URL . '/index.php';
$badge = ['calculated'=>'secondary','reviewed'=>'info','finalized'=>'success'][$run['status']] ?? 'secondary';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;"><?= htmlspecialchars($run['run_number']) ?>
            <span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?> ms-2" style="font-size:.6em; vertical-align:middle;"><?= strtoupper($run['status']) ?></span>
        </h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">As of <?= htmlspecialchars($run['as_of_date']) ?> — <?= htmlspecialchars($periodName) ?> — Policy <?= htmlspecialchars($policyVersion) ?></p>
    </div>
    <a href="<?= $base ?>?page=loan-provisioning" class="btn btn-outline-secondary btn-sm">&larr; Back to Runs</a>
</div>

<?php if ($run['status'] === 'finalized'): ?>
<div class="alert alert-success d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-lock-fill flex-shrink-0"></i>
    <div>This run is <strong>finalized and immutable</strong>. Its figures are a permanent historical snapshot and cannot be edited, even if underlying loan/repayment data later changes. Corrections must be made through a new forward run.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Total Exposure</div><div class="fw-bold"><?= number_format((float)$run['total_exposure'], 2) ?></div></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Required Provision</div><div class="fw-bold"><?= number_format((float)$run['total_required_provision'], 2) ?></div></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Previous Provision</div><div class="fw-bold"><?= number_format((float)$run['total_previous_provision'], 2) ?></div></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Delta</div><div class="fw-bold"><?= number_format((float)$run['total_delta'], 2) ?></div></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Loans</div><div class="fw-bold"><?= (int)$run['loan_count'] ?></div></div></div></div>
    <div class="col-md-2"><div class="card text-center"><div class="card-body py-2"><div class="text-muted small">Journal Entry</div><div class="fw-bold"><?= $run['journal_entry_id'] ? '#' . (int)$run['journal_entry_id'] : '—' ?></div></div></div></div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-wrap gap-2">
        <div class="me-4 small text-muted">
            Calculated by <?= htmlspecialchars($calculatedByName ?? '—') ?> on <?= htmlspecialchars($run['calculated_at'] ?? '') ?><br>
            <?php if ($run['reviewed_by']): ?>Reviewed by <?= htmlspecialchars($reviewedByName) ?> on <?= htmlspecialchars($run['reviewed_at']) ?><br><?php endif; ?>
            <?php if ($run['finalized_by']): ?>Finalized by <?= htmlspecialchars($finalizedByName) ?> on <?= htmlspecialchars($run['finalized_at']) ?><?php endif; ?>
        </div>

        <?php if ($run['status'] === 'calculated' && $canReview): ?>
        <form method="POST" action="<?= $base ?>?page=loan-provisioning-review" class="ms-auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
            <button type="submit" class="btn btn-info btn-sm"><i class="bi bi-eye-fill me-1"></i>Mark Reviewed</button>
        </form>
        <?php endif; ?>

        <?php if (in_array($run['status'], ['calculated','reviewed'], true) && $canFinalize): ?>
        <?php $sameActor = (int)$run['calculated_by'] === $currentUserId; ?>
        <button type="button" class="btn btn-danger btn-sm" data-bs-toggle="modal" data-bs-target="#finalizeModal" <?= $sameActor ? 'disabled title="Maker-checker: you calculated this run and cannot also finalize it."' : '' ?>>
            <i class="bi bi-check2-circle me-1"></i>Finalize / Post
        </button>
        <?php if ($sameActor): ?><span class="text-danger small align-self-center">You calculated this run — a different authorized user must finalize it (maker-checker).</span><?php endif; ?>
        <?php endif; ?>

        <?php if ($run['status'] === 'finalized' && $canFinalize): ?>
        <form method="POST" action="<?= $base ?>?page=loan-provisioning-correction" class="ms-auto">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
            <button type="submit" class="btn btn-outline-warning btn-sm" onclick="return confirm('This creates a NEW forward correction run. The original finalized run will remain unchanged. Continue?');">
                <i class="bi bi-arrow-repeat me-1"></i>Create Correction Run
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>

<?php if (in_array($run['status'], ['calculated','reviewed'], true) && $canFinalize): ?>
<div class="modal fade" id="finalizeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= $base ?>?page=loan-provisioning-finalize">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="run_id" value="<?= $run['id'] ?>">
                <div class="modal-header"><h5 class="modal-title">Confirm Finalization</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
                <div class="modal-body">
                    <p>This will <strong>permanently finalize</strong> run <?= htmlspecialchars($run['run_number']) ?> and cannot be undone or edited afterward.</p>
                    <ul>
                        <li>Delta: <strong><?= number_format((float)$run['total_delta'], 2) ?></strong> (<?= (float)$run['total_delta'] >= 0 ? 'increase' : 'decrease' ?> in allowance)</li>
                        <li>Accounts affected: 5300 Bad Debt/Loan Impairment Provision Expense, 1185 Allowance for Impairment on Loans</li>
                        <li>Accounting period: <?= htmlspecialchars($periodName) ?></li>
                        <li>Policy version: <?= htmlspecialchars($policyVersion) ?></li>
                    </ul>
                    <p class="text-danger small mb-0">Once finalized, this run's figures become a permanent, immutable historical record. Corrections require a new forward run.</p>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-danger">Yes, Finalize and Post</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Loan-Level Detail (frozen snapshot)</h6></div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Loan</th><th>Member</th><th class="text-end">Principal</th><th class="text-end">Paid</th>
                    <th class="text-end">Exposure</th><th>Due Date</th><th class="text-end">Days</th><th>Bucket</th>
                    <th class="text-end">Rate</th><th class="text-end">Required</th><th class="text-end">Previous</th><th class="text-end">Delta</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($details as $d): ?>
                <tr class="<?= $d['exclusion_reason'] ? 'table-warning' : '' ?>">
                    <td class="ps-3">#<?= (int)$d['loan_id'] ?></td>
                    <td>#<?= (int)$d['member_id'] ?></td>
                    <td class="text-end"><?= number_format((float)$d['original_principal'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['qualifying_principal_paid'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['unpaid_principal_exposure'], 2) ?></td>
                    <td><?= htmlspecialchars($d['oldest_qualifying_due_date'] ?? '—') ?></td>
                    <td class="text-end"><?= (int)$d['days_past_due'] ?></td>
                    <td><?= htmlspecialchars($d['bucket_name']) ?></td>
                    <td class="text-end"><?= number_format((float)$d['applied_rate'], 2) ?>%</td>
                    <td class="text-end"><?= number_format((float)$d['required_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['previous_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$d['delta'], 2) ?></td>
                </tr>
                <?php if ($d['exclusion_reason']): ?>
                <tr class="table-warning"><td></td><td colspan="11" class="small text-danger"><i class="bi bi-exclamation-triangle me-1"></i><?= htmlspecialchars($d['exclusion_reason']) ?></td></tr>
                <?php endif; ?>
                <?php endforeach; ?>
                <?php if (empty($details)): ?>
                <tr><td colspan="12" class="text-center text-muted py-4">No eligible loans in this run.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
