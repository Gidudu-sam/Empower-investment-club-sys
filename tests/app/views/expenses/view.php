<?php
/**
 * Full-width "financial record" layout (2026-09 redesign): replaced the
 * previous split 8/4 column layout (large left detail column + a mostly-
 * empty right action column) with a single-column stack of full-width
 * sections -- Identity -> Status/Amount -> Post action (if applicable) ->
 * Expense Information -> Accounting Entry -> Audit Trail. Actions live in
 * the page-title CTA slot, not a dedicated sidebar card. Currency stays
 * "Shs" (existing app-wide convention, not "UGX") per explicit decision.
 */
$canWrite  = Session::hasRole(['admin', 'treasurer']);
$isDraft   = $expense['status'] === 'draft';
$isPosted  = $expense['status'] === 'posted';
$hasGl     = !empty($expense['gl_account_id']);

ob_start(); ?>
<a href="<?= APP_URL ?>/index.php?page=expenses" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i> Back to Expenses
</a>
<?php if ($canWrite): ?>
<a href="<?= APP_URL ?>/index.php?page=expense-create" class="btn btn-outline-primary btn-sm">
    <i class="bi bi-plus-circle me-1"></i> Record Another Expense
</a>
<?php endif;
$cta      = ob_get_clean();
$icon     = 'bi-receipt';
$title    = 'Expense ' . htmlspecialchars($expense['expense_number']);
$subtitle = $expense['description'] ? htmlspecialchars($expense['description']) : null;
$pageTitle = $pageTitle ?? ('Expense ' . $expense['expense_number']);
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

    <!-- Status + amount bar -->
    <div class="card mb-4">
        <div class="card-body">
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <?php if ($isPosted): ?>
                        <span class="badge bg-success-subtle text-success px-3 py-2">
                            <i class="bi bi-check-circle-fill me-1"></i>Posted
                        </span>
                    <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning px-3 py-2">
                            <i class="bi bi-clock-fill me-1"></i>Draft
                        </span>
                    <?php endif; ?>
                    <?php if ($isPosted && $expense['entry_number']): ?>
                        <span class="text-muted">&middot;</span>
                        <span class="small fw-semibold"><?= htmlspecialchars($expense['entry_number']) ?></span>
                    <?php endif; ?>
                    <span class="text-muted">&middot;</span>
                    <span class="small"><?= htmlspecialchars($expense['payment_method']) ?></span>
                    <span class="text-muted">&middot;</span>
                    <span class="small"><?= date('d M Y', strtotime($expense['expense_date'])) ?></span>
                </div>
                <div class="h3 fw-bold mb-0">
                    Shs <?= number_format((float)$expense['amount'], 0) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Post to ledger (draft, write access only) -->
    <?php if ($isDraft && $canWrite): ?>
    <div class="card mb-4 <?= $hasGl ? 'border-success' : 'border-warning' ?>">
        <div class="card-header <?= $hasGl ? 'bg-success text-white' : 'bg-warning text-dark' ?>">
            <i class="bi bi-journal-arrow-up me-2"></i>Post to Ledger
        </div>
        <div class="card-body">
            <?php if ($hasGl): ?>
                <div class="row align-items-center g-3">
                    <div class="col-md-8">
                        <p class="small text-muted mb-2">
                            Posting will write this expense permanently to the accounting ledger.
                            This action cannot be undone — the journal entry will be immutable.
                        </p>
                        <div class="d-flex flex-wrap gap-4 small">
                            <div>
                                <span class="text-muted">Dr</span>
                                <span class="fw-semibold"><?= htmlspecialchars($expense['account_code'] . ' ' . $expense['account_name']) ?></span>
                                <span class="fw-semibold">Shs <?= number_format((float)$expense['amount'], 0) ?></span>
                            </div>
                            <div>
                                <span class="text-muted">Cr</span>
                                <span class="fw-semibold">1110 Cash at Hand</span>
                                <span class="fw-semibold">Shs <?= number_format((float)$expense['amount'], 0) ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4 text-md-end">
                        <form method="POST" action="<?= APP_URL ?>/index.php?page=expense-post"
                              onsubmit="return confirm('Post Shs <?= number_format((float)$expense['amount'], 0) ?> to the ledger? This cannot be undone.');">
                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                            <input type="hidden" name="expense_id" value="<?= $expense['id'] ?>">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-journal-check me-1"></i> Post to Ledger
                            </button>
                        </form>
                    </div>
                </div>
            <?php else: ?>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <i class="bi bi-exclamation-triangle-fill text-warning fs-5 flex-shrink-0"></i>
                    <p class="small mb-0 flex-grow-1">
                        The <strong><?= htmlspecialchars($expense['category_name']) ?></strong> category
                        has no GL account mapped. Set one in
                        <a href="<?= APP_URL ?>/index.php?page=expense-categories">Expense Categories</a>
                        before posting.
                    </p>
                    <button class="btn btn-secondary btn-sm" disabled>
                        <i class="bi bi-slash-circle me-1"></i> Cannot Post
                    </button>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Expense information -->
    <div class="card mb-4">
        <div class="card-header">
            <i class="bi bi-info-circle me-2"></i>Expense Information
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Expense Date</div>
                    <div class="fw-semibold"><?= date('d M Y', strtotime($expense['expense_date'])) ?></div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Category</div>
                    <div class="fw-semibold">
                        <?= htmlspecialchars($expense['category_name']) ?>
                        <?php if ($expense['category_group'] ?? ''): ?>
                            <span class="text-muted fw-normal">&middot; <?= htmlspecialchars($expense['category_group']) ?></span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="text-muted small mb-1">Payment Method</div>
                    <div class="fw-semibold"><?= htmlspecialchars($expense['payment_method']) ?></div>
                </div>

                <?php if ($expense['payee_name']): ?>
                <div class="col-md-6">
                    <div class="text-muted small mb-1">Payee / Supplier</div>
                    <div class="fw-semibold"><?= htmlspecialchars($expense['payee_name']) ?></div>
                </div>
                <?php endif; ?>

                <?php if (!empty($expense['cash_reference_number'])): ?>
                <div class="col-md-6">
                    <div class="text-muted small mb-1">Cash Reference</div>
                    <div class="fw-semibold"><?= htmlspecialchars($expense['cash_reference_number']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($expense['reference_number']): ?>
                <div class="col-md-6">
                    <div class="text-muted small mb-1">Reference / Receipt</div>
                    <div class="fw-semibold"><?= htmlspecialchars($expense['reference_number']) ?></div>
                </div>
                <?php endif; ?>

                <?php if ($expense['description']): ?>
                <div class="col-12">
                    <div class="text-muted small mb-1">Description</div>
                    <div class="fw-semibold"><?= htmlspecialchars($expense['description']) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Accounting entry (only when posted) -->
    <?php if ($isPosted && $expense['entry_number']): ?>
    <div class="card border-success mb-4">
        <div class="card-header d-flex align-items-center justify-content-between">
            <span><i class="bi bi-journal-check me-2 text-success"></i>Accounting Entry</span>
            <span class="badge bg-success-subtle text-success"><i class="bi bi-check2 me-1"></i>Balanced</span>
        </div>
        <div class="card-body">
            <p class="mb-3">
                <strong><?= htmlspecialchars($expense['entry_number']) ?></strong>
                <?php if (!empty($expense['approved_date'])): ?>
                    &middot; Posted <?= date('d M Y', strtotime($expense['approved_date'])) ?>
                <?php endif; ?>
                <?php if (!empty($expense['approved_by_name'])): ?>
                    <span class="text-muted">&middot; by <?= htmlspecialchars($expense['approved_by_name']) ?></span>
                <?php endif; ?>
            </p>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-3 small">
                    <thead class="table-light">
                        <tr>
                            <th>Account</th>
                            <th class="text-end">Debit</th>
                            <th class="text-end">Credit</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td><?= htmlspecialchars($expense['account_code'] . ' — ' . $expense['account_name']) ?></td>
                            <td class="text-end">Shs <?= number_format((float)$expense['amount'], 0) ?></td>
                            <td class="text-end text-muted">—</td>
                        </tr>
                        <tr>
                            <td>1110 — Cash at Hand</td>
                            <td class="text-end text-muted">—</td>
                            <td class="text-end">Shs <?= number_format((float)$expense['amount'], 0) ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="text-end">
                <a href="<?= APP_URL ?>/index.php?page=report-general-ledger&account_id=<?= (int)$expense['gl_account_id'] ?>"
                   class="btn btn-outline-success btn-sm">
                    <i class="bi bi-bar-chart me-1"></i> View in General Ledger
                </a>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Audit trail (real events only -- no fabricated timeline entries) -->
    <div class="card">
        <div class="card-header">
            <i class="bi bi-clock-history me-2"></i>Audit Trail
        </div>
        <div class="card-body">
            <ul class="list-unstyled mb-0">
                <li class="d-flex gap-3 <?= ($isPosted && $expense['entry_number']) ? 'pb-3 mb-3 border-bottom' : '' ?>">
                    <span class="flex-shrink-0 mt-1" style="width:.55rem;height:.55rem;border-radius:50%;background:var(--brand-navy,#0d3b66);display:inline-block"></span>
                    <div>
                        <div class="fw-semibold small">Expense recorded</div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($expense['recorded_by_name']) ?>
                            &middot; <?= date('d M Y', strtotime($expense['created_at'] ?? $expense['expense_date'])) ?>
                        </div>
                    </div>
                </li>
                <?php if ($isPosted && $expense['entry_number']): ?>
                <li class="d-flex gap-3">
                    <span class="flex-shrink-0 mt-1" style="width:.55rem;height:.55rem;border-radius:50%;background:#198754;display:inline-block"></span>
                    <div>
                        <div class="fw-semibold small">Posted to ledger &middot; <?= htmlspecialchars($expense['entry_number']) ?></div>
                        <div class="text-muted small">
                            <?= htmlspecialchars($expense['approved_by_name'] ?? '—') ?>
                            <?php if (!empty($expense['approved_date'])): ?>
                                &middot; <?= date('d M Y', strtotime($expense['approved_date'])) ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

