<?php
$pageTitle = $pageTitle ?? 'Reverse Balance Brought Forward';
$base = APP_URL . '/index.php';
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-arrow-counterclockwise me-2"></i>Reverse Balance Brought Forward
                    — <?= htmlspecialchars($account['account_number']) ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning small">
                        This does <strong>not</strong> edit or delete the original entry — it remains visible in the
                        ledger for audit purposes. Reversing posts an equal-and-opposite Balance Brought Forward entry
                        that brings the net effect back to zero, after which a corrected Balance Brought Forward can
                        be recorded. No General Ledger journal is affected, because the original never created one.
                    </div>

                    <?php if ($bfRow): ?>
                    <div class="alert alert-secondary small">
                        <div class="d-flex justify-content-between"><span>Receipt</span><strong><?= htmlspecialchars($bfRow['receipt_number']) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Amount</span><strong>Shs <?= number_format((float)$bfRow['credit'], 2) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Effective Date</span><strong><?= date('d M Y', strtotime($bfRow['transaction_date'])) ?></strong></div>
                        <div class="d-flex justify-content-between"><span>Notes</span><strong class="text-end"><?= htmlspecialchars($bfRow['notes'] ?? '') ?></strong></div>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=savings-account-bf-reverse-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">
                        <input type="hidden" name="savings_id" value="<?= (int)($bfRow['id'] ?? 0) ?>">

                        <div class="mb-3">
                            <label class="form-label">Reason for Reversal</label>
                            <textarea name="reason" class="form-control" rows="3" required
                                      placeholder="e.g. Amount entered incorrectly — should have been Shs 450,000, not Shs 500,000."></textarea>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-danger">Reverse Balance Brought Forward</button>
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
