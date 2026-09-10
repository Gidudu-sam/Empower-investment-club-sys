<?php
$pageTitle = $pageTitle ?? 'Fixed Deposit Closure Requests';
$title     = $pageTitle;
$icon      = 'bi-clipboard-check';
$base      = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('success')): ?>
        <div class="alert alert-success"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header"><i class="bi bi-clipboard-check me-2 text-primary"></i>Pending Approval</div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Request #</th>
                        <th>Account</th>
                        <th>Member</th>
                        <th class="text-end">Payout Amount</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($requests)): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">No pending closure requests.</td></tr>
                    <?php else: foreach ($requests as $r): ?>
                    <tr>
                        <td>#<?= (int)$r['id'] ?></td>
                        <td><?= htmlspecialchars($r['account_number']) ?></td>
                        <td><?= htmlspecialchars($r['first_name'] . ' ' . $r['last_name']) ?> (<?= htmlspecialchars($r['member_number']) ?>)</td>
                        <td class="text-end fw-semibold">Shs <?= number_format((float)$r['payout_amount'], 2) ?></td>
                        <td><?= date('d M Y', strtotime($r['requested_at'])) ?></td>
                        <td>
                            <a href="<?= $base ?>?page=savings-account-fd-closure-review&id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-primary">
                                Review
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
            </div>
        </div>
    </div>
