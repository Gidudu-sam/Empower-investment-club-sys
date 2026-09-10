<?php
$pageTitle = $pageTitle ?? 'Savings Account Settlements';
$title     = $pageTitle;
$icon      = 'bi-cash-coin';
$base      = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="alert alert-secondary small">
        Fixed Deposit payouts are handled on their own dedicated queue —
        <a href="<?= $base ?>?page=savings-account-fd-payouts">Fixed Deposit Payouts</a>.
    </div>

    <div class="card">
        <div class="card-header"><i class="bi bi-cash-coin me-2 text-primary"></i>Approved — Awaiting Settlement</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request #</th>
                        <th>Type</th>
                        <th>Account</th>
                        <th>Member</th>
                        <th class="text-end">Balance at Request</th>
                        <th>Approved</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">No settlements awaiting processing.</td></tr>
                    <?php else: foreach ($requests as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($r['account_type']) ?></span></td>
                        <td><?= htmlspecialchars($r['account_number']) ?></td>
                        <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?> (<?= htmlspecialchars($r['member_number']) ?>)</td>
                        <td class="text-end fw-semibold">Shs <?= number_format((float)$r['settlement_amount'], 2) ?></td>
                        <td><?= date('d M Y', strtotime($r['approved_at'])) ?></td>
                        <td>
                            <a href="<?= $base ?>?page=savings-account-settle-form&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                Settle
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
