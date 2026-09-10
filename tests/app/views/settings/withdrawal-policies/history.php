<?php
$base = APP_URL . '/index.php';
$canWrite = Session::hasRole(['admin']);
$labels = ['compulsory' => 'Compulsory', 'voluntary' => 'Voluntary', 'joint' => 'Joint', 'corporate' => 'Corporate'];
$today = date('Y-m-d');
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-clock-history me-2 text-danger"></i><?= htmlspecialchars($labels[$accountType] ?? ucfirst($accountType)) ?> Policy History
        </h1>
        <p class="text-muted mb-0 small">Every version ever configured — nothing is overwritten in place.</p>
    </div>
    <a href="<?= $base ?>?page=withdrawal-policies" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-3">Enabled</th>
                    <th class="text-end">Max</th>
                    <th class="text-end">Share</th>
                    <th>Frequency</th>
                    <th>Effective From</th>
                    <th>Effective To</th>
                    <th class="text-center">Status</th>
                    <th>Created By</th>
                    <?php if ($canWrite): ?><th class="text-end pe-3">Actions</th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="9" class="text-center text-muted py-4">No policy has ever been configured for this account type.</td></tr>
                <?php else: foreach ($history as $p):
                    $isFuture = $p['effective_from'] > $today;
                ?>
                <tr class="<?= $p['status'] === 'inactive' ? 'text-muted' : '' ?>">
                    <td class="ps-3"><?= $p['withdrawal_enabled'] ? 'Yes' : 'No' ?></td>
                    <td class="text-end"><?= number_format((float)$p['maximum_withdrawal_percent'], 2) ?>%</td>
                    <td class="text-end"><?= number_format((float)$p['share_conversion_percent'], 2) ?>%</td>
                    <td><?= htmlspecialchars(str_replace('_', ' ', ucfirst($p['frequency']))) ?></td>
                    <td><?= date('d M Y', strtotime($p['effective_from'])) ?></td>
                    <td><?= $p['effective_to'] ? date('d M Y', strtotime($p['effective_to'])) : '— (open)' ?></td>
                    <td class="text-center">
                        <?php if ($p['status'] === 'active' && !$p['effective_to']): ?>
                            <span class="badge bg-success-subtle text-success"><?= $isFuture ? 'Scheduled' : 'Current' ?></span>
                        <?php elseif ($p['status'] === 'active'): ?>
                            <span class="badge bg-secondary-subtle text-secondary">Superseded</span>
                        <?php else: ?>
                            <span class="badge bg-warning-subtle text-warning">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($p['created_by_name'] ?? '—') ?></td>
                    <?php if ($canWrite): ?>
                    <td class="text-end pe-3">
                        <?php if ($isFuture): ?>
                        <a href="<?= $base ?>?page=withdrawal-policy-edit-draft&id=<?= $p['id'] ?>" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil"></i> Edit
                        </a>
                        <?php endif; ?>
                        <form method="POST" action="<?= $base ?>?page=withdrawal-policy-toggle" class="d-inline"
                              onsubmit="return confirm('<?= $p['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this policy version?')">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn btn-sm <?= $p['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                <?= $p['status'] === 'active' ? 'Deactivate' : 'Activate' ?>
                            </button>
                        </form>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
