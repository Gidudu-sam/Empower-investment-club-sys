<?php
$pageTitle = $pageTitle ?? 'Internal Vouchers';
$title = $pageTitle;
$icon  = 'bi-journal-check';
$canWrite = Session::hasRole(['admin', 'treasurer']);

$statusMap = [
    'draft'            => 'bg-secondary',
    'pending_approval' => 'bg-warning text-dark',
    'approved'         => 'bg-info text-dark',
    'posted'           => 'bg-success',
    'rejected'         => 'bg-danger',
];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-journal-check me-2"></i>Internal Vouchers</span>
            <?php if ($canWrite): ?>
            <a href="<?= APP_URL ?>/index.php?page=internal-voucher-create" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-circle me-1"></i> New Voucher
            </a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if (Session::has('success')): ?>
                <div class="alert alert-success"><?= Session::flash('success') ?></div>
            <?php endif; ?>
            <?php if (Session::has('error')): ?>
                <div class="alert alert-danger"><?= Session::flash('error') ?></div>
            <?php endif; ?>

            <form method="GET" action="<?= APP_URL ?>/index.php" class="row g-2 mb-3">
                <input type="hidden" name="page" value="internal-vouchers">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search number or being..." value="<?= htmlspecialchars($filters['search']) ?>">
                </div>
                <div class="col-md-2">
                    <select name="voucher_type" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <option value="debit" <?= $filters['type'] === 'debit' ? 'selected' : '' ?>>Debit Voucher</option>
                        <option value="credit" <?= $filters['type'] === 'credit' ? 'selected' : '' ?>>Credit Voucher</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft', 'pending_approval', 'approved', 'rejected', 'posted'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['dateFrom']) ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?= htmlspecialchars($filters['dateTo']) ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= APP_URL ?>/index.php?page=internal-vouchers" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Voucher #</th>
                            <th>Type</th>
                            <th>Date</th>
                            <th>Account</th>
                            <th>Contra Account</th>
                            <th>Being</th>
                            <th class="text-end">Amount</th>
                            <th>Prepared By</th>
                            <th>Approved By</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($vouchers)): ?>
                            <tr><td colspan="11" class="text-center text-muted">No internal vouchers recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($vouchers as $v): ?>
                                <?php $cls = $statusMap[$v['status']] ?? 'bg-secondary'; ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($v['voucher_number']) ?></strong></td>
                                    <td>
                                        <span class="badge bg-<?= $v['voucher_type'] === 'debit' ? 'primary' : 'success' ?>-subtle text-<?= $v['voucher_type'] === 'debit' ? 'primary' : 'success' ?>">
                                            <?= $v['voucher_type'] === 'debit' ? 'Debit' : 'Credit' ?>
                                        </span>
                                    </td>
                                    <td><?= date('d M Y', strtotime($v['voucher_date'])) ?></td>
                                    <td><?= htmlspecialchars($v['primary_account_code'] . ' — ' . $v['primary_account_name']) ?></td>
                                    <td><?= htmlspecialchars($v['contra_account_code'] . ' — ' . $v['contra_account_name']) ?></td>
                                    <td><?= htmlspecialchars($v['narration']) ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$v['amount'], 2) ?></td>
                                    <td><?= htmlspecialchars($v['recorded_by_name'] ?? '-') ?></td>
                                    <td><?= htmlspecialchars($v['approved_by_name'] ?? '-') ?></td>
                                    <td><span class="badge <?= $cls ?>"><?= ucwords(str_replace('_', ' ', $v['status'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=internal-voucher-view&id=<?= $v['id'] ?>" class="btn btn-sm btn-outline-primary">
                                            <i class="bi bi-eye"></i> View
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
