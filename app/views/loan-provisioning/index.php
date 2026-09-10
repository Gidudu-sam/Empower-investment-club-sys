<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold" style="font-family:'Space Grotesk',sans-serif;">Loan Provisioning</h1>
        <p class="text-muted mb-0" style="font-size:.78rem;">Accounting &rsaquo; Loan Provisioning — policy PROV-001, principal-only exposure, period-end runs</p>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="<?= $base ?>?page=loan-provisioning-report-summary" class="btn btn-outline-secondary btn-sm">Summary</a>
        <a href="<?= $base ?>?page=loan-provisioning-report-by-bucket" class="btn btn-outline-secondary btn-sm">By Bucket</a>
        <a href="<?= $base ?>?page=loan-provisioning-report-loan-level" class="btn btn-outline-secondary btn-sm">Loan-Level</a>
        <a href="<?= $base ?>?page=loan-provisioning-report-movement" class="btn btn-outline-secondary btn-sm">Movement</a>
        <?php if ($canCalculate): ?>
        <a href="<?= $base ?>?page=loan-provisioning-calculate" class="btn btn-primary btn-sm"><i class="bi bi-calculator me-1"></i>Calculate Run</a>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="loan-provisioning">
            <div class="col-md-3">
                <label class="form-label small">Status</label>
                <select name="status" class="form-select form-select-sm">
                    <option value="">All</option>
                    <?php foreach (['calculated','reviewed','finalized'] as $s): ?>
                        <option value="<?= $s ?>" <?= $statusFilter === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small">Accounting Period</label>
                <select name="accounting_period_id" class="form-select form-select-sm">
                    <option value="0">All</option>
                    <?php foreach ($periods as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= $periodFilter === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-sm btn-outline-primary w-100">Filter</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold" style="font-size:.82rem;">Provisioning Runs</h6>
        <span class="badge bg-primary-subtle text-primary"><?= count($runs) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Run</th>
                    <th>As-Of Date</th>
                    <th>Policy</th>
                    <th class="text-end">Exposure</th>
                    <th class="text-end">Required</th>
                    <th class="text-end">Delta</th>
                    <th class="text-center">Status</th>
                    <th>Journal</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($runs)): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">No provisioning runs yet.</td></tr>
                <?php endif; ?>
                <?php foreach ($runs as $r): ?>
                <tr>
                    <td class="ps-3"><a href="<?= $base ?>?page=loan-provisioning-view&id=<?= $r['id'] ?>"><?= htmlspecialchars($r['run_number']) ?></a></td>
                    <td><?= htmlspecialchars($r['as_of_date']) ?></td>
                    <td><?= htmlspecialchars($r['policy_version']) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_exposure'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_required_provision'], 2) ?></td>
                    <td class="text-end"><?= number_format((float)$r['total_delta'], 2) ?></td>
                    <td class="text-center">
                        <?php $badge = ['calculated'=>'secondary','reviewed'=>'info','finalized'=>'success'][$r['status']] ?? 'secondary'; ?>
                        <span class="badge bg-<?= $badge ?>-subtle text-<?= $badge ?>"><?= strtoupper($r['status']) ?></span>
                    </td>
                    <td><?= $r['journal_entry_id'] ? '#' . (int)$r['journal_entry_id'] : '<span class="text-muted">—</span>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
