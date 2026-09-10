<?php
$base = APP_URL . '/index.php';
$canWrite = Session::hasRole(['admin']);
$labels = ['compulsory' => 'Compulsory', 'voluntary' => 'Voluntary', 'joint' => 'Joint', 'corporate' => 'Corporate'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-sliders me-2 text-danger"></i>Savings Withdrawal Policies
        </h1>
        <p class="text-muted mb-0 small">Configure withdrawal limits and share-conversion rules per savings account type</p>
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

<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<div class="alert alert-info d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>
        This configuration defines the RULES only — it does not process withdrawals,
        move money, or post any journal entry. Percentages here are consumed by the
        withdrawal transaction engine when it is implemented.
    </div>
</div>

<div class="card">
    <div class="card-header d-flex align-items-center gap-2">
        <i class="bi bi-table text-danger"></i>
        <h6 class="mb-0 fw-semibold">Current Policy by Account Type</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Account Type</th>
                    <th class="text-center">Enabled</th>
                    <th class="text-end">Max Withdrawal</th>
                    <th class="text-end">Share Conversion</th>
                    <th>Frequency</th>
                    <th>Effective From</th>
                    <th class="text-center">Status</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($accountTypes as $type): $p = $currentPolicies[$type] ?? null; ?>
                <tr>
                    <td class="ps-3 fw-semibold"><?= htmlspecialchars($labels[$type] ?? ucfirst($type)) ?></td>
                    <?php if ($p): ?>
                        <td class="text-center">
                            <?php if ($p['withdrawal_enabled']): ?>
                                <span class="badge bg-success-subtle text-success">Yes</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary">No</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-end"><?= number_format((float)$p['maximum_withdrawal_percent'], 2) ?>%</td>
                        <td class="text-end"><?= number_format((float)$p['share_conversion_percent'], 2) ?>%</td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($p['frequency']))) ?></td>
                        <td><?= date('d M Y', strtotime($p['effective_from'])) ?></td>
                        <td class="text-center"><span class="badge bg-success-subtle text-success">Active</span></td>
                    <?php else: ?>
                        <td colspan="5" class="text-center text-muted">
                            <i class="bi bi-dash-circle me-1"></i>No active policy configured
                        </td>
                        <td class="text-center"><span class="badge bg-warning-subtle text-warning">Not Configured</span></td>
                    <?php endif; ?>
                    <td class="text-end pe-3">
                        <a href="<?= $base ?>?page=withdrawal-policy-history&account_type=<?= $type ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-clock-history"></i> History
                        </a>
                        <?php if ($canWrite): ?>
                        <a href="<?= $base ?>?page=withdrawal-policy-create&account_type=<?= $type ?>" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-plus-circle"></i> New Version
                        </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="card-footer text-muted small">
        Joint and Corporate account types have no approved withdrawal rule yet — "Not
        Configured" is treated as withdrawals not being permitted, never as an implicit default.
    </div>
</div>
