<?php
$isDeposit = $mode === 'deposit';
$pageTitle = $pageTitle ?? ($isDeposit ? 'Record Deposit' : 'Record Withdrawal');
$title = $pageTitle;
$icon  = $isDeposit ? 'bi-plus-circle' : 'bi-dash-circle';
$base = APP_URL . '/index.php';
$methods = ['Cash', 'Airtel Money', 'MTN Mobile Money', 'Bank Transfer', 'Cheque', 'Other'];

$jointHolders = array_values(array_filter($holders, fn($h) => !empty($h['member_id'])));
$isJoint = $account['account_type'] === 'joint';
$policy = $policy ?? null;
$maxWithdrawal = $maxWithdrawal ?? $balance;
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($msg = Session::flash('error')): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi <?= $icon ?> me-2"></i><?= $isDeposit ? 'Record Deposit' : 'Record Withdrawal' ?>
                    — <?= htmlspecialchars($account['account_number']) ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Account Type</span>
                        <strong class="text-capitalize"><?= htmlspecialchars($account['account_type']) ?></strong>
                    </div>
                    <div class="alert alert-secondary small d-flex justify-content-between">
                        <span>Current Balance</span>
                        <strong>Shs <?= number_format($balance, 2) ?></strong>
                    </div>

                    <?php if (!$isDeposit && $policy): ?>
                    <div class="alert alert-info small">
                        <div class="d-flex justify-content-between">
                            <span>Applicable Withdrawal Policy</span>
                            <strong class="text-capitalize"><?= htmlspecialchars($account['account_type']) ?> — <?= htmlspecialchars($policy['frequency']) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span>Maximum Withdrawal (<?= number_format((float)$policy['maximum_withdrawal_percent'], 2) ?>%)</span>
                            <strong>Shs <?= number_format($maxWithdrawal, 2) ?></strong>
                        </div>
                        <?php if ((float)$policy['share_conversion_percent'] > 0): ?>
                        <div class="d-flex justify-content-between">
                            <span>Share Conversion</span>
                            <strong><?= number_format((float)$policy['share_conversion_percent'], 2) ?>% of the qualifying balance not withdrawn converts to Share Capital</strong>
                        </div>
                        <?php else: ?>
                        <div class="d-flex justify-content-between">
                            <span>Share Conversion</span>
                            <strong>None — remaining balance stays in this account</strong>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= $base ?>?page=savings-account-<?= $isDeposit ? 'deposit' : 'withdrawal' ?>-store">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">

                        <?php if ($isJoint): ?>
                        <div class="mb-3">
                            <label class="form-label">Holder Making This <?= $isDeposit ? 'Deposit' : 'Withdrawal' ?></label>
                            <select name="member_id" class="form-select" required>
                                <option value="">Select Holder</option>
                                <?php foreach ($jointHolders as $h): ?>
                                    <option value="<?= (int)$h['member_id'] ?>">
                                        <?= htmlspecialchars(trim($h['first_name'] . ' ' . $h['last_name'])) ?>
                                        (<?= htmlspecialchars($h['member_number']) ?>)<?= $h['role'] === 'primary' ? ' — Primary' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php else: ?>
                            <?php $only = $jointHolders[0] ?? null; ?>
                            <div class="mb-3">
                                <label class="form-label">Account Holder</label>
                                <input type="text" class="form-control" disabled
                                       value="<?= $only ? htmlspecialchars(trim($only['first_name'] . ' ' . $only['last_name']) . ' (' . $only['member_number'] . ')') : '' ?>">
                            </div>
                        <?php endif; ?>

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount (Shs)</label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" name="amount" class="form-control" step="0.01"
                                           min="0.01"
                                           <?= $isDeposit ? '' : 'max="' . number_format($maxWithdrawal, 2, '.', '') . '"' ?> required>
                                </div>
                                <?php if (!$isDeposit): ?>
                                    <div class="form-text">Cannot exceed the maximum withdrawal allowed under the applicable policy (Shs <?= number_format($maxWithdrawal, 2) ?>). This is a convenience limit only — the server independently re-checks and enforces the actual policy.</div>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Payment Method</label>
                                <select name="payment_method" id="paymentMethodSelect" class="form-select" required>
                                    <?php foreach ($methods as $pm): ?>
                                        <option value="<?= $pm ?>" <?= $pm === 'Cash' ? 'selected' : '' ?>><?= $pm ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6" id="cashReferenceNote">
                                <label class="form-label">Cash Reference</label>
                                <input type="text" class="form-control" value="Will be generated automatically" disabled readonly>
                            </div>
                            <div class="col-md-6" id="externalReferenceField" style="display:none;">
                                <label class="form-label">Reference Number <small class="text-muted">(optional)</small></label>
                                <input type="text" name="reference_number" class="form-control" maxlength="100" placeholder="Mobile money code, cheque no.">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Notes <small class="text-muted">(optional)</small></label>
                                <textarea name="notes" class="form-control" rows="2"></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn <?= $isDeposit ? 'btn-primary' : 'btn-danger' ?>">
                                <?= $isDeposit ? 'Record Deposit' : 'Record Withdrawal' ?>
                            </button>
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <script>
        (function () {
            var methodSel   = document.getElementById('paymentMethodSelect');
            var cashNote    = document.getElementById('cashReferenceNote');
            var externalRef = document.getElementById('externalReferenceField');
            if (!methodSel || !cashNote || !externalRef) { return; }
            function toggle() {
                var isCash = methodSel.value === 'Cash';
                cashNote.style.display    = isCash ? '' : 'none';
                externalRef.style.display = isCash ? 'none' : '';
            }
            methodSel.addEventListener('change', toggle);
            toggle();
        })();
        </script>
    </div>
