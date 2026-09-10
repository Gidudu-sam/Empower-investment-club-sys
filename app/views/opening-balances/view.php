<?php
$pageTitle = $pageTitle ?? 'Opening Balance';
$title = $pageTitle;
$icon  = 'bi-clipboard2-data';

$statusMap = [
    'draft'            => 'bg-secondary',
    'pending_approval'  => 'bg-warning text-dark',
    'approved'         => 'bg-info text-dark',
    'posted'           => 'bg-success',
    'rejected'         => 'bg-danger',
];
$statusClass = $statusMap[$batch['status']] ?? 'bg-secondary';
$statusLabel = ucwords(str_replace('_', ' ', $batch['status']));

$totalDebit = array_sum(array_column($batch['lines'], 'debit'));
$totalCredit = array_sum(array_column($batch['lines'], 'credit'));

$canApproveReject = $isAdmin && $batch['status'] === 'pending_approval' && (int)$batch['entered_by'] !== $currentUserId;
$selfApprovalBlocked = $isAdmin && $batch['status'] === 'pending_approval' && (int)$batch['entered_by'] === $currentUserId;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('success')): ?>
        <div class="alert alert-success"><?= Session::flash('success') ?></div>
    <?php endif; ?>
    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-clipboard2-data me-2"></i>Batch <?= htmlspecialchars($batch['batch_number']) ?></span>
                    <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-4"><strong>Financial Year:</strong> <?= htmlspecialchars($batch['financial_year_name'] ?? '') ?></div>
                        <div class="col-md-4"><strong>Accounting Period:</strong> <?= htmlspecialchars($batch['accounting_period_name'] ?? '') ?></div>
                        <div class="col-md-4"><strong>As Of:</strong> <?= date('d M Y', strtotime($batch['as_of_date'])) ?></div>
                    </div>
                    <div class="row mb-3">
                        <div class="col-md-4"><strong>Prepared By:</strong> <?= htmlspecialchars($batch['entered_by_name'] ?? '') ?></div>
                        <div class="col-md-4"><strong>Approved By:</strong> <?= htmlspecialchars($batch['approved_by_name'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Rejected By:</strong> <?= htmlspecialchars($batch['rejected_by_name'] ?? '-') ?></div>
                    </div>
                    <?php if ($batch['status'] === 'rejected' && $batch['rejection_reason']): ?>
                        <div class="alert alert-danger">
                            <strong>Rejection reason:</strong> <?= htmlspecialchars($batch['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if ($batch['status'] === 'posted'): ?>
                        <div class="alert alert-success">
                            Posted as journal entry #<?= (int)$batch['journal_entry_id'] ?> on
                            <?= $batch['posted_at'] ? date('d M Y H:i', strtotime($batch['posted_at'])) : '' ?>.
                        </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Account</th>
                                    <th class="text-end">Debit</th>
                                    <th class="text-end">Credit</th>
                                    <th>Description</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($batch['lines'] as $line): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($line['account_code'] . ' — ' . $line['account_name']) ?></td>
                                        <td class="text-end"><?= $line['debit'] > 0 ? number_format((float)$line['debit'], 2) : '' ?></td>
                                        <td class="text-end"><?= $line['credit'] > 0 ? number_format((float)$line['credit'], 2) : '' ?></td>
                                        <td><?= htmlspecialchars($line['description'] ?? '') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot class="table-light">
                                <tr>
                                    <th>Total</th>
                                    <th class="text-end">Shs <?= number_format($totalDebit, 2) ?></th>
                                    <th class="text-end">Shs <?= number_format($totalCredit, 2) ?></th>
                                    <th></th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2"></i>Workflow History</div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        <?php if (empty($auditTrail)): ?>
                            <li class="list-group-item text-muted small">No history yet.</li>
                        <?php else: ?>
                            <?php foreach ($auditTrail as $ev): ?>
                                <li class="list-group-item small">
                                    <strong><?= ucfirst($ev['action']) ?></strong>
                                    by <?= htmlspecialchars($ev['user_name'] ?? ('user #' . $ev['user_id'])) ?>
                                    on <?= date('d M Y H:i', strtotime($ev['created_at'])) ?>
                                    <?php if (!empty($ev['reason'])): ?>
                                        — <em><?= htmlspecialchars($ev['reason']) ?></em>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card">
                <div class="card-header"><i class="bi bi-gear me-2"></i>Actions</div>
                <div class="card-body d-flex flex-column gap-2">
                    <?php if ($canWrite && ($batch['status'] === 'draft' || $batch['status'] === 'rejected')): ?>
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=opening-balance-submit">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Submit for Approval
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canApproveReject): ?>
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=opening-balance-approve">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Approve this opening balance batch?');">
                                <i class="bi bi-check-circle me-1"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=opening-balance-reject">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <div class="mb-2">
                                <label class="form-label small">Rejection reason (required)</label>
                                <textarea name="rejection_reason" class="form-control form-control-sm" required></textarea>
                            </div>
                            <button type="submit" class="btn btn-danger w-100">
                                <i class="bi bi-x-circle me-1"></i> Reject
                            </button>
                        </form>
                    <?php elseif ($selfApprovalBlocked): ?>
                        <div class="alert alert-warning small mb-0">
                            You prepared this batch and cannot approve or reject your own submission. Another administrator must review it.
                        </div>
                    <?php endif; ?>

                    <?php if ($canWrite && $batch['status'] === 'approved'): ?>
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=opening-balance-post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="batch_id" value="<?= $batch['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Post this approved batch as a journal entry? This cannot be undone.');">
                                <i class="bi bi-journal-check me-1"></i> Post to Ledger
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="<?= APP_URL ?>/index.php?page=opening-balances" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
