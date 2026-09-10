<?php
$pageTitle = $pageTitle ?? ('Other Income ' . ($income['income_number'] ?? ''));
$title = $pageTitle;
$icon  = 'bi-cash-stack';
$canWrite  = Session::hasRole(['admin', 'treasurer']);
$isDraft   = $income['status'] === 'draft';
$isPosted  = $income['status'] === 'posted';
$hasGl     = !empty($income['gl_account_id']);
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($flash = Session::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="row g-4">

        <!-- ── LEFT: Income detail ─────────────────────────────────────── -->
        <div class="col-12 col-xl-8">

            <!-- Header card -->
            <div class="card mb-4">
                <div class="card-body">
                    <div class="d-flex align-items-start justify-content-between flex-wrap gap-3">
                        <div>
                            <div class="text-muted small mb-1">
                                <i class="bi bi-cash-stack me-1"></i>Other Income Reference
                            </div>
                            <h4 class="fw-bold mb-1"><?= htmlspecialchars($income['income_number']) ?></h4>
                            <div class="text-muted small">
                                Recorded by <strong><?= htmlspecialchars($income['recorded_by_name']) ?></strong>
                                on <?= date('d M Y', strtotime($income['created_at'] ?? $income['income_date'])) ?>
                            </div>
                        </div>
                        <div>
                            <?php if ($isPosted): ?>
                                <span class="badge bg-success fs-6 px-3 py-2">
                                    <i class="bi bi-check-circle me-1"></i>Posted
                                </span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark fs-6 px-3 py-2">
                                    <i class="bi bi-clock me-1"></i>Draft
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Main details -->
            <div class="card mb-4">
                <div class="card-header">
                    <i class="bi bi-info-circle me-2"></i>Income Details
                </div>
                <div class="card-body">

                    <!-- Amount — make it stand out -->
                    <div class="text-center py-3 mb-4 rounded-3 bg-light">
                        <div class="text-muted small mb-1">Amount</div>
                        <div class="display-6 fw-bold text-dark">
                            Shs <?= number_format((float)$income['amount'], 0) ?>
                        </div>
                        <div class="text-muted small mt-1">
                            Received via <?= htmlspecialchars($income['payment_method']) ?>
                        </div>
                    </div>

                    <!-- Key fields grid -->
                    <div class="row g-3">
                        <div class="col-sm-6">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small mb-1"><i class="bi bi-calendar3 me-1"></i>Income Date</div>
                                <div class="fw-semibold"><?= date('d M Y', strtotime($income['income_date'])) ?></div>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small mb-1"><i class="bi bi-tag me-1"></i>Category</div>
                                <div class="fw-semibold"><?= htmlspecialchars($income['category_name']) ?></div>
                            </div>
                        </div>

                        <?php if ($income['description']): ?>
                        <div class="col-12">
                            <div class="p-3 border rounded-3">
                                <div class="text-muted small mb-1"><i class="bi bi-chat-left-text me-1"></i>Description</div>
                                <div class="fw-semibold"><?= htmlspecialchars($income['description']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if (!empty($income['cash_reference_number'])): ?>
                        <div class="col-sm-6">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small mb-1"><i class="bi bi-hash me-1"></i>Cash Reference</div>
                                <div class="fw-semibold"><?= htmlspecialchars($income['cash_reference_number']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ($income['reference_number']): ?>
                        <div class="col-sm-6">
                            <div class="p-3 border rounded-3 h-100">
                                <div class="text-muted small mb-1"><i class="bi bi-hash me-1"></i>Reference / Receipt</div>
                                <div class="fw-semibold"><?= htmlspecialchars($income['reference_number']) ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Posted journal entry info (only when posted) -->
            <?php if ($isPosted && $income['entry_number']): ?>
            <div class="card border-success">
                <div class="card-header bg-success text-white">
                    <i class="bi bi-journal-check me-2"></i>Accounting Entry
                </div>
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-8">
                            <p class="mb-1">
                                Posted as journal entry
                                <strong class="text-success"><?= htmlspecialchars($income['entry_number']) ?></strong>
                                <?php if (!empty($income['posted_by_name'])): ?>
                                    by <strong><?= htmlspecialchars($income['posted_by_name']) ?></strong>
                                <?php endif; ?>
                                <?php if (!empty($income['posted_at'])): ?>
                                    on <?= date('d M Y', strtotime($income['posted_at'])) ?>
                                <?php endif; ?>
                            </p>
                            <!-- Journal mini-table -->
                            <div class="table-responsive">
                                <table class="table table-sm table-bordered mt-2 mb-0 small">
                                <thead class="table-light">
                                    <tr>
                                        <th>Account</th>
                                        <th class="text-end">Debit</th>
                                        <th class="text-end">Credit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td>Cash/Bank (<?= htmlspecialchars($income['payment_method']) ?>)</td>
                                        <td class="text-end">Shs <?= number_format((float)$income['amount'], 0) ?></td>
                                        <td class="text-end text-muted">—</td>
                                    </tr>
                                    <tr>
                                        <td><?= htmlspecialchars($income['account_code'] . ' — ' . $income['account_name']) ?></td>
                                        <td class="text-end text-muted">—</td>
                                        <td class="text-end">Shs <?= number_format((float)$income['amount'], 0) ?></td>
                                    </tr>
                                </tbody>
                            </table>
                            </div>
                        </div>
                        <div class="col-md-4 text-center">
                            <a href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=<?= (int)$income['gl_account_id'] ?>"
                               class="btn btn-outline-success w-100">
                                <i class="bi bi-bar-chart me-1"></i> View in General Ledger
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

        </div>

        <!-- ── RIGHT: Actions sidebar ────────────────────────────────────── -->
        <div class="col-12 col-xl-4">

            <!-- Post action -->
            <?php if ($isDraft && $canWrite): ?>
            <div class="card mb-3 <?= $hasGl ? 'border-success' : 'border-warning' ?>">
                <div class="card-header <?= $hasGl ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
                    <i class="bi bi-journal-arrow-up me-2"></i>Post to Ledger
                </div>
                <div class="card-body">
                    <?php if ($hasGl): ?>
                        <p class="small text-muted mb-3">
                            Posting will write this Other Income permanently to the accounting ledger.
                            This action cannot be undone — the journal entry will be immutable.
                        </p>
                        <div class="bg-light rounded-3 p-2 mb-3 small">
                            <div class="d-flex justify-content-between">
                                <span class="text-muted">Dr Cash/Bank (<?= htmlspecialchars($income['payment_method']) ?>)</span>
                                <span class="fw-semibold">Shs <?= number_format((float)$income['amount'], 0) ?></span>
                            </div>
                            <div class="d-flex justify-content-between mt-1">
                                <span class="text-muted">Cr <?= htmlspecialchars($income['account_code'] . ' ' . $income['account_name']) ?></span>
                                <span class="fw-semibold">Shs <?= number_format((float)$income['amount'], 0) ?></span>
                            </div>
                        </div>
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=other-income-post"
                              onsubmit="return confirm('Post Shs <?= number_format((float)$income['amount'], 0) ?> to the ledger? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="income_id" value="<?= $income['id'] ?>">
                            <button type="submit" class="btn btn-success w-100 btn-lg">
                                <i class="bi bi-journal-check me-1"></i> Post to Ledger
                            </button>
                        </form>
                    <?php else: ?>
                        <div class="d-flex gap-2 mb-3">
                            <i class="bi bi-exclamation-triangle-fill text-warning fs-5 flex-shrink-0"></i>
                            <p class="small mb-0">
                                The <strong><?= htmlspecialchars($income['category_name']) ?></strong> category
                                has no GL account mapped. Set one in
                                <a href="<?= APP_URL ?>/index.php?page=other-income-categories">Other Income Categories</a>
                                before posting.
                            </p>
                        </div>
                        <button class="btn btn-secondary w-100" disabled>
                            <i class="bi bi-slash-circle me-1"></i> Cannot Post — No GL Account
                        </button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Already posted notice -->
            <?php if ($isPosted): ?>
            <div class="card mb-3 border-success">
                <div class="card-body text-center py-4">
                    <i class="bi bi-check-circle-fill text-success fs-2 mb-2 d-block"></i>
                    <div class="fw-semibold text-success">Other Income Posted</div>
                    <div class="text-muted small mt-1">Journal entry <?= htmlspecialchars($income['entry_number']) ?></div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Navigation -->
            <div class="card">
                <div class="card-body d-flex flex-column gap-2">
                    <a href="<?= APP_URL ?>/index.php?page=other-income" class="btn btn-outline-secondary w-100">
                        <i class="bi bi-arrow-left me-1"></i> Back to Other Income
                    </a>
                    <?php if ($canWrite && $isDraft): ?>
                        <a href="<?= APP_URL ?>/index.php?page=other-income-categories" class="btn btn-outline-secondary w-100">
                            <i class="bi bi-tags me-1"></i> Manage Categories
                        </a>
                    <?php endif; ?>
                    <a href="<?= APP_URL ?>/index.php?page=other-income-create" class="btn btn-outline-primary w-100">
                        <i class="bi bi-plus-circle me-1"></i> Record Another Income
                    </a>
                </div>
            </div>

        </div>
    </div>
