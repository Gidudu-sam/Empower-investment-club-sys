<?php
$pageTitle = $pageTitle ?? 'Balance Brought Forward';
$base = APP_URL . '/index.php';
$jointHolders = array_values(array_filter($holders, fn($h) => !empty($h['member_id'])));
$only = $jointHolders[0] ?? null;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-clock-history me-2"></i>Historical Balance Brought Forward
                    — <?= htmlspecialchars($account['account_number']) ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Account Type</span>
                        <strong class="text-capitalize"><?= htmlspecialchars($account['account_type']) ?></strong>
                    </div>
                    <?php if ($only): ?>
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Account Holder</span>
                        <strong><?= htmlspecialchars(trim($only['first_name'] . ' ' . $only['last_name']) . ' (' . $only['member_number'] . ')') ?></strong>
                    </div>
                    <?php endif; ?>

                    <div class="alert alert-info small">
                        This records genuine historical savings the member already accumulated before being entered
                        into this system — it is <strong>not</strong> a new deposit. It will not affect Cash, Bank, or
                        Mobile Money balances, and no General Ledger journal entry will be created.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=savings-account-bf-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount Brought Forward (Shs)</label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" name="amount" class="form-control" step="0.01" min="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Effective / As-of Date</label>
                                <input type="date" name="effective_date" class="form-control" max="<?= date('Y-m-d') ?>" required>
                                <div class="form-text">The date this historical balance represents (e.g. the end of the accumulated period) — never today's date, unless that genuinely is the as-of date.</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label mb-1">Historical Period Represented</label>
                                <div class="form-text mt-0 mb-2">Enter the historical period covered by this Balance Brought Forward amount.</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Period From</label>
                                <input type="date" name="period_from" class="form-control" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Period To</label>
                                <input type="date" name="period_to" class="form-control" required>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Record Balance Brought Forward</button>
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-info-circle me-2"></i>What Happens Next</div>
                <div class="card-body small">
                    <ul class="mb-0 ps-3">
                        <li>The account's savings balance increases by this amount.</li>
                        <li>It appears on the member's statement as "Balance Brought Forward," dated by the effective date above.</li>
                        <li>It counts toward the Shs 40,000 compulsory-savings qualification amount, but not toward the required count of 2 deposit events.</li>
                        <li><strong>No cash, bank, or mobile-money balance changes.</strong> No General Ledger journal entry is created.</li>
                        <li>Only one Balance Brought Forward is allowed per account. If entered incorrectly, it must be reversed (not edited) before a corrected one can be recorded.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
