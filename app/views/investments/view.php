<?php
$pageTitle = $pageTitle ?? 'Investment';
$title = $pageTitle;
$icon  = 'bi-graph-up';
$base = APP_URL . '/index.php';

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
$statusClass = $statusMap[$investment['status']] ?? 'bg-secondary';
$statusLabel = ucwords(str_replace('_', ' ', $investment['status']));

$canApproveReject = $isAdmin && $investment['status'] === 'pending_approval' && (int)$investment['recorded_by'] !== $currentUserId;
$selfApprovalBlocked = $isAdmin && $investment['status'] === 'pending_approval' && (int)$investment['recorded_by'] === $currentUserId;
$canTransact = $canWrite && in_array($investment['status'], ['posted', 'matured', 'withdrawn'], true);
$typeMap = ['income' => 'success', 'withdrawal' => 'warning', 'disposal' => 'dark'];
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
                    <span><i class="bi bi-graph-up me-2"></i><?= htmlspecialchars($investment['investment_number']) ?> — <?= htmlspecialchars($investment['type_name']) ?></span>
                    <span class="badge <?= $statusClass ?>"><?= $statusLabel ?></span>
                </div>
                <div class="card-body">
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Provider:</strong> <?= htmlspecialchars($investment['provider_name'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Reference:</strong> <?= htmlspecialchars($investment['reference'] ?? '-') ?></div>
                        <div class="col-md-4"><strong>Expected Rate:</strong> <?= $investment['expected_rate'] !== null ? number_format((float)$investment['expected_rate'], 4) . '%' : '-' ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Start Date:</strong> <?= date('d M Y', strtotime($investment['start_date'])) ?></div>
                        <div class="col-md-4"><strong>Maturity Date:</strong> <?= $investment['maturity_date'] ? date('d M Y', strtotime($investment['maturity_date'])) : 'Open-ended' ?></div>
                        <div class="col-md-4"><strong>Principal:</strong> Shs <?= number_format((float)$investment['principal_amount'], 2) ?></div>
                    </div>
                    <div class="row mb-2">
                        <div class="col-md-4"><strong>Investment Account:</strong> <?= htmlspecialchars($investment['investment_account_code'] . ' — ' . $investment['investment_account_name']) ?></div>
                        <div class="col-md-4"><strong>Funding Account:</strong> <?= htmlspecialchars($investment['funding_account_code'] . ' — ' . $investment['funding_account_name']) ?></div>
                        <div class="col-md-4"><strong>Prepared By:</strong> <?= htmlspecialchars($investment['recorded_by_name'] ?? '') ?></div>
                    </div>
                    <?php if ($investment['notes']): ?>
                        <p class="mt-2"><strong>Notes:</strong> <?= nl2br(htmlspecialchars($investment['notes'])) ?></p>
                    <?php endif; ?>

                    <?php if ($investment['status'] === 'rejected' && $investment['rejection_reason']): ?>
                        <div class="alert alert-danger mt-3">
                            <strong>Rejection reason:</strong> <?= htmlspecialchars($investment['rejection_reason']) ?>
                        </div>
                    <?php endif; ?>
                    <?php if (!empty($investment['journal_entry_id'])): ?>
                        <div class="alert alert-success mt-3 mb-0">
                            Placement posted as journal entry <a href="<?= $base ?>?page=report-general-ledger&account_id=<?= $investment['investment_account_id'] ?>"><?= htmlspecialchars($investment['entry_number']) ?></a>
                            <?= $investment['posted_at'] ? ' on ' . date('d M Y H:i', strtotime($investment['posted_at'])) : '' ?>.
                        </div>
                    <?php endif; ?>

                    <div class="row g-3 mt-3">
                        <div class="col-6 col-md-6">
                            <div class="stat-card stat-card-primary h-100">
                                <div class="stat-label">Current Carrying Amount</div>
                                <div class="stat-value">Shs <?= number_format((float)$investment['carrying_amount'], 2) ?></div>
                            </div>
                        </div>
                        <div class="col-6 col-md-6">
                            <div class="stat-card stat-card-success h-100">
                                <div class="stat-label">Total Income Received</div>
                                <div class="stat-value">Shs <?= number_format((float)$investment['income_received'], 2) ?></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="bi bi-arrow-left-right me-2"></i>Transactions</span>
                    <?php if ($canTransact): ?>
                    <a href="<?= $base ?>?page=investment-transaction-create&investment_id=<?= $investment['id'] ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-plus-circle me-1"></i> Record Transaction
                    </a>
                    <?php endif; ?>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Transaction #</th>
                                <th>Type</th>
                                <th>Date</th>
                                <th class="text-end">Amount</th>
                                <th>Funding Account</th>
                                <th>Status</th>
                                <th>Journal Entry</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($transactions)): ?>
                                <tr><td colspan="7" class="text-center text-muted">No transactions recorded yet.</td></tr>
                            <?php else: ?>
                                <?php foreach ($transactions as $t): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($t['transaction_number']) ?></td>
                                        <td><span class="badge bg-<?= $typeMap[$t['transaction_type']] ?? 'secondary' ?>"><?= ucfirst($t['transaction_type']) ?></span></td>
                                        <td><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                                        <td class="text-end">Shs <?= number_format((float)$t['amount'], 2) ?></td>
                                        <td><?= htmlspecialchars(($t['funding_account_code'] ?? '') . ' ' . ($t['funding_account_name'] ?? '')) ?></td>
                                        <td><?= $t['status'] === 'posted' ? '<span class="badge bg-success">Posted</span>' : '<span class="badge bg-secondary">Draft</span>' ?></td>
                                        <td><?= $t['entry_number'] ? htmlspecialchars($t['entry_number']) : '-' ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
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
                    <?php if ($canWrite && in_array($investment['status'], ['draft', 'rejected'], true)): ?>
                        <form method="POST" action="<?= $base ?>?page=investment-submit">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="investment_id" value="<?= $investment['id'] ?>">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-send me-1"></i> Submit for Approval
                            </button>
                        </form>
                    <?php endif; ?>

                    <?php if ($canApproveReject): ?>
                        <form method="POST" action="<?= $base ?>?page=investment-approve">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="investment_id" value="<?= $investment['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Approve this investment?');">
                                <i class="bi bi-check-circle me-1"></i> Approve
                            </button>
                        </form>
                        <form method="POST" action="<?= $base ?>?page=investment-reject">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="investment_id" value="<?= $investment['id'] ?>">
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
                            You prepared this investment and cannot approve or reject your own submission. Another administrator must review it.
                        </div>
                    <?php endif; ?>

                    <?php if ($canWrite && $investment['status'] === 'approved'): ?>
                        <form method="POST" action="<?= $base ?>?page=investment-post">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="investment_id" value="<?= $investment['id'] ?>">
                            <button type="submit" class="btn btn-success w-100" onclick="return confirm('Post this approved investment as a journal entry? This cannot be undone.');">
                                <i class="bi bi-journal-check me-1"></i> Post to Ledger
                            </button>
                        </form>
                    <?php endif; ?>

                    <a href="<?= $base ?>?page=investments" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-left me-1"></i> Back to List
                    </a>
                </div>
            </div>
        </div>
    </div>
