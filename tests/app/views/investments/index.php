<?php
$pageTitle = $pageTitle ?? 'Investments';
$title = $pageTitle;
$icon  = 'bi-graph-up';
$canWrite = Session::hasRole(['admin', 'treasurer']);
$isAdmin  = Session::hasRole(['admin']);

$statusMap = [
    'draft'            => 'bg-secondary',
    'pending_approval' => 'bg-warning text-dark',
    'approved'         => 'bg-info text-dark',
    'posted'           => 'bg-success',
    'rejected'         => 'bg-danger',
    'matured'          => 'bg-primary',
    'withdrawn'        => 'bg-dark',
    'disposed'         => 'bg-dark',
];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-graph-up me-2"></i>Investments</span>
            <div class="d-flex gap-2">
                <?php if ($isAdmin): ?>
                <a href="<?= APP_URL ?>/index.php?page=investment-types" class="btn btn-sm btn-outline-secondary">
                    <i class="bi bi-tags me-1"></i> Investment Types
                </a>
                <?php endif; ?>
                <?php if ($canWrite): ?>
                <a href="<?= APP_URL ?>/index.php?page=investment-create" class="btn btn-sm btn-primary">
                    <i class="bi bi-plus-circle me-1"></i> Record Investment
                </a>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-body">
            <?php if (Session::has('success')): ?>
                <div class="alert alert-success"><?= Session::flash('success') ?></div>
            <?php endif; ?>
            <?php if (Session::has('error')): ?>
                <div class="alert alert-danger"><?= Session::flash('error') ?></div>
            <?php endif; ?>

            <form method="GET" action="<?= APP_URL ?>/index.php" class="row g-2 mb-3">
                <input type="hidden" name="page" value="investments">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control form-control-sm" placeholder="Search number, provider, reference..." value="<?= htmlspecialchars($filters['search']) ?>">
                </div>
                <div class="col-md-2">
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All Statuses</option>
                        <?php foreach (['draft', 'pending_approval', 'approved', 'rejected', 'posted', 'matured', 'withdrawn', 'disposed'] as $s): ?>
                            <option value="<?= $s ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= ucwords(str_replace('_', ' ', $s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <select name="investment_type_id" class="form-select form-select-sm">
                        <option value="">All Types</option>
                        <?php foreach ($types as $t): ?>
                            <option value="<?= $t['id'] ?>" <?= (string)$filters['typeId'] === (string)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_from" class="form-control form-control-sm" placeholder="Start from" value="<?= htmlspecialchars($filters['dateFrom']) ?>">
                </div>
                <div class="col-md-2">
                    <input type="date" name="date_to" class="form-control form-control-sm" placeholder="Start to" value="<?= htmlspecialchars($filters['dateTo']) ?>">
                </div>
                <div class="col-12 d-flex gap-2">
                    <button type="submit" class="btn btn-sm btn-outline-primary"><i class="bi bi-search me-1"></i>Filter</button>
                    <a href="<?= APP_URL ?>/index.php?page=investments" class="btn btn-sm btn-outline-secondary">Clear</a>
                </div>
            </form>

            <div class="table-responsive">
                <table class="table table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>Investment #</th>
                            <th>Type</th>
                            <th>Provider</th>
                            <th class="text-end">Principal</th>
                            <th>Start Date</th>
                            <th>Maturity Date</th>
                            <th class="text-end">Carrying Amount</th>
                            <th class="text-end">Income Received</th>
                            <th>Status</th>
                            <th class="text-end">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($investments)): ?>
                            <tr><td colspan="10" class="text-center text-muted">No investments recorded yet.</td></tr>
                        <?php else: ?>
                            <?php foreach ($investments as $inv): ?>
                                <?php $cls = $statusMap[$inv['status']] ?? 'bg-secondary'; ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($inv['investment_number']) ?></strong></td>
                                    <td><?= htmlspecialchars($inv['type_name']) ?></td>
                                    <td><?= htmlspecialchars($inv['provider_name'] ?? '-') ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$inv['principal_amount'], 2) ?></td>
                                    <td><?= date('d M Y', strtotime($inv['start_date'])) ?></td>
                                    <td><?= $inv['maturity_date'] ? date('d M Y', strtotime($inv['maturity_date'])) : '-' ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$inv['carrying_amount'], 2) ?></td>
                                    <td class="text-end">Shs <?= number_format((float)$inv['income_received'], 2) ?></td>
                                    <td><span class="badge <?= $cls ?>"><?= ucwords(str_replace('_', ' ', $inv['status'])) ?></span></td>
                                    <td class="text-end">
                                        <a href="<?= APP_URL ?>/index.php?page=investment-view&id=<?= $inv['id'] ?>" class="btn btn-sm btn-outline-primary">
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
