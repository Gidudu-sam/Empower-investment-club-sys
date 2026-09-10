<?php
$pageTitle = $pageTitle ?? 'Internal Voucher';
$title = $pageTitle;
$icon  = 'bi-journal-check';
$base = APP_URL . '/index.php';

$statusMap = [
    'draft'            => 'bg-secondary',
    'pending_approval' => 'bg-warning text-dark',
    'approved'         => 'bg-info text-dark',
    'posted'           => 'bg-success',
    'rejected'         => 'bg-danger',
];
$statusClass = $statusMap[$voucher['status']] ?? 'bg-secondary';
$statusLabel = ucwords(str_replace('_', ' ', $voucher['status']));
$isDebit = $voucher['voucher_type'] === 'debit';

$canApproveReject = $isApprover && $voucher['status'] === 'pending_approval' && (int)$voucher['recorded_by'] !== $currentUserId;
$selfApprovalBlocked = $isApprover && $voucher['status'] === 'pending_approval' && (int)$voucher['recorded_by'] === $currentUserId;
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
                    <span>
                        <i class="bi bi-journal-check me-2"></i><?= htmlspecialchars($voucher['voucher_number']) ?>
                        — <span class="badge bg-<?= $isDebit ? 'primary' : 'success' ?>-subtle text-<?= $isDebit ? 'primary' : 'success' ?>"><?= $isDebit ? 'Internal Debit Voucher' : 'Internal Credit Voucher' ?></span>
                    </span>
                    <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-6"><strong><?= $isDebit ? 'Debit' : 'Credit' ?> Account:</strong> <?= htmlspecialchars($voucher['primary_account_name'] . ' (' . $voucher['primary_account_code'] . ')') ?></div>
                        <div class="col-md-6"><strong><?= $isDebit ? 'Credit' : 'Debit' ?> Account:</strong> <?= htmlspecialchars($voucher['contra_account_name'] . ' (' . $voucher['contra_account_code'] . ')') ?></div>
                    </div>
                    <?php if ($voucher['category_name']): ?>
                    <div class="row mb-2">
                        <div class="col-md-6"><strong>Expense Category:</strong> <?= htmlspecialchars($voucher['category_name']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($voucher['member_id'])): ?>
                    <div class="row mb-2">
                        <div class="col-md-6"><strong>Member:</strong> <?= htmlspecialchars($voucher['member_first_name'] . ' ' . $voucher['member_last_name'] . ' (' . $voucher['member_number'] . ')') ?></div>
                        <div class="col-md-6"><strong>Savings Account:</strong> <?= htmlspecialchars(ucfirst($voucher['savings_account_type']) . ' — ' . $voucher['savings_account_number']) ?></div>
                    </div>
                    <div class="row mb-2">
                        <?php if ($voucher['status'] === 'posted'): ?>
                            <div class="col-md-6"><strong>Balance Before:</strong> Shs <?= number_format((float)$voucher['balance_before'], 2) ?></div>
                            <div class="col-md-6"><strong>Balance After:</strong> Shs <?= number_format((float)$voucher['balance_after'], 2) ?></div>
                        <?php else: ?>
                            <div class="col-md-6"><strong>Account Balance Now:</strong> Shs <?= number_format((float)$currentBalance, 2) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="row mb-2">
                        <div class="col-md-6"><strong>Voucher Date:</strong> <?= date('d M Y', strtotime($voucher['voucher_date'])) ?></div>
                        <div class="col-md-6"><strong>Amount:</strong> Shs <?= number_format((float)$voucher['amount'], 2) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-12"><strong>Being:</strong> <?= nl2br(htmlspecialchars($voucher['narration'])) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-6"><strong>Prepared By:</strong> <?= htmlspecialchars($voucher['recorded_by_name'] ?? '') ?></div>
                        <div class="col-md-6"><strong>Approved By:</strong> <?= htmlspecialchars($voucher['approved_by_name'] ?? '-') ?></div>
                    </div>

                    <?php if ($voucher['status'] === 'rejected' && $voucher['rejection_reason']): ?>
                        <div class="alert alert-danger mt-3">
                            <strong>Rejection reason:</strong> <?= htmlspecialchars($voucher['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($voucher['journal_entry_id'])): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            Posted as journal entry <?= htmlspecialchars($voucher['entry_number']) ?>
                            <?= $voucher['posted_at'] ? ' on ' . date('d M Y H:i', strtotime($voucher['posted_at'])) : '' ?>.
                        </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <a href="<?= $base ?>?page=internal-voucher-print&id=<?= $voucher['id'] ?>" target="_blank" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-printer me-1"></i> Print Voucher
                        </a>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><i class="bi bi-clock-history me-2"></i>Approval History</div>
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
                    <?php if ($canWrite && in_array($voucher['status'], ['draft', 'rejected'], true)): ?>
                        <form method="POST" action="<?= $base ?>?page=internal-voucher-submit">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Submit for Approval
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canApproveReject): ?>
                        <form method="POST" action="<?= $base ?>?page=internal-voucher-approve">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Approve this voucher?');">
                                <i class="bi bi-check-circle me-1"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="<?= $base ?>?page=internal-voucher-reject">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
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
                            You prepared this voucher and cannot approve or reject your own submission. Another administrator must review it.
                        </div>
                    <?php endif; ?>

                    <?php if ($canWrite && $voucher['status'] === 'approved'): ?>
                        <form method="POST" action="<?= $base ?>?page=internal-voucher-post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="voucher_id" value="<?= $voucher['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Post this approved voucher as a journal entry? This cannot be undone.');">
                                <i class="bi bi-journal-check me-1"></i> Post to Ledger
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="<?= $base ?>?page=internal-vouchers" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
