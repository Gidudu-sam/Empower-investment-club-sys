<?php
$pageTitle = $pageTitle ?? 'Savings Summary';
$title = $pageTitle;
$icon  = 'bi-bank';
$base = APP_URL . '/index.php';

$typeLabels = ['compulsory' => 'Compulsory Savings', 'voluntary' => 'Voluntary Savings', 'joint' => 'Joint Savings', 'corporate' => 'Corporate Savings', 'fixed_deposit' => 'Fixed Deposit'];
$statusLabels = ['active' => 'Active', 'dormant' => 'Dormant', 'closed' => 'Closed'];
$statusBadge = ['active' => 'bg-success', 'dormant' => 'bg-warning text-dark', 'closed' => 'bg-secondary'];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card mb-3">
        <div class="card-header"><i class="bi bi-person me-2"></i><?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?> (<?= htmlspecialchars($member['member_number']) ?>)</div>
        <div class="card-body">
            <p class="text-muted small mb-0">
                Consolidated view of all savings accounts this member holds or co-holds. Each account's balance
                remains separate and is never merged; the grand total below is a display-only sum for convenience.
            </p>
        </div>
    </div>

    <div class="card">
        <div class="card-header">Savings Accounts</div>
        <div class="table-responsive">
            <table class="table table-sm mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Account No.</th>
                        <th>Type</th>
                        <th>Status</th>
                        <th>Opened</th>
                        <th class="text-end">Balance</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($summary['accounts'])): ?>
                        <tr><td colspan="6" class="text-center text-muted">No savings accounts found for this member.</td></tr>
                    <?php else: ?>
                        <?php foreach ($summary['accounts'] as $a): ?>
                            <tr>
                                <td><strong><?= htmlspecialchars($a['account_number']) ?></strong></td>
                                <td>
                                    <?= htmlspecialchars($typeLabels[$a['account_type']] ?? $a['account_type']) ?>
                                </td>
                                <td><span class="badge <?= $statusBadge[$a['status']] ?? 'bg-secondary' ?>"><?= htmlspecialchars($statusLabels[$a['status']] ?? $a['status']) ?></span></td>
                                <td><?= date('d M Y', strtotime($a['opened_date'])) ?></td>
                                <td class="text-end">Shs <?= number_format((float)$a['balance'], 2) ?></td>
                                <td class="text-end">
                                    <a href="<?= $base ?>?page=savings-account-view&id=<?= $a['id'] ?>" class="btn btn-sm btn-outline-primary">View</a>
                                    <a href="<?= $base ?>?page=savings-account-statement&id=<?= $a['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">Statement</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
                <?php if (!empty($summary['accounts'])): ?>
                <tfoot class="table-light">
                    <tr><th colspan="4">Grand Total</th><th class="text-end">Shs <?= number_format($summary['total'], 2) ?></th><th></th></tr>
                </tfoot>
                <?php endif; ?>
            </table>
        </div>
    </div>

    <a href="<?= $base ?>?page=savings-accounts" class="btn btn-outline-secondary btn-sm mt-3">
        <i class="bi bi-arrow-left me-1"></i> Back to Savings Accounts
    </a>
