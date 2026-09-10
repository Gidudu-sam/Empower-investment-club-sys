<?php
$pageTitle = $pageTitle ?? 'Create Accounting Period';
$title = $pageTitle;
$icon  = 'bi-calendar-plus';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-8">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-calendar-plus me-2"></i>Create Accounting Period
                </div>
                <div class="card-body">
                    <?php if (Session::has('error')): ?>
                        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
                    <?php endif; ?>

                    <form method="POST" action="<?= APP_URL ?>/index.php?page=accounting-period-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <div class="mb-3">
                            <label class="form-label">Financial Year</label>
                            <select name="financial_year_id" class="form-select" required>
                                <option value="">Select Financial Year</option>
                                <?php foreach ($financialYears as $fy): ?>
                                    <option value="<?= $fy['id'] ?>">
                                        <?= htmlspecialchars($fy['name']) ?> 
                                        (<?= $fy['start_date'] ?> to <?= $fy['end_date'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Period Name</label>
                            <input type="text" name="name" class="form-control" 
                                   placeholder="e.g., Q1 2026" required>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Start Date</label>
                                <input type="date" name="start_date" class="form-control" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">End Date</label>
                                <input type="date" name="end_date" class="form-control" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="open">Open</option>
                                <option value="closed">Closed</option>
                            </select>
                            <small class="text-muted">
                                New periods are typically created as "Open". 
                                Only close a period when all transactions are finalized.
                            </small>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Create Period
                            </button>
                            <a href="<?= APP_URL ?>/index.php?page=accounting-periods" class="btn btn-secondary">
                                Cancel
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
