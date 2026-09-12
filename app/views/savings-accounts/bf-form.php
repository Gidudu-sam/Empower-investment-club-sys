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
                        into this system — it is <strong>not</strong> a new deposit. Choose below whether the club
                        currently holds real funds backing this balance, or whether it is historical-only.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=savings-account-bf-store" id="bfForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="account_id" value="<?= (int)$account['id'] ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount Brought Forward (Shs)</label>
                                <div class="input-group">
                                    <span class="input-group-text">Shs</span>
                                    <input type="number" name="amount" id="bfAmount" class="form-control" step="0.01" min="0.01" required>
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

                            <div class="col-12">
                                <label class="form-label mb-1">Funds Position / Posting Mode</label>
                                <div class="form-text mt-0 mb-2">Does the club currently hold real funds backing this balance?</div>
                                <select name="posting_mode" id="bfPostingMode" class="form-select" required>
                                    <option value="">Select…</option>
                                    <?php foreach ($bfAssetMethods as $method): ?>
                                    <option value="verified_asset" data-method="<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?> — funds verified held by the club</option>
                                    <?php endforeach; ?>
                                    <option value="historical_only">Historical Only — asset not independently verified</option>
                                </select>
                            </div>

                            <div class="col-12" id="bfPreviewBox" style="display:none;">
                                <div class="alert alert-secondary small mb-0" id="bfPreviewText"></div>
                            </div>

                            <div class="col-12" id="bfReasonBox" style="display:none;">
                                <label class="form-label">Reason (why the corresponding club asset has not been independently verified)</label>
                                <textarea name="historical_only_reason" id="bfReasonText" class="form-control" rows="2" placeholder="e.g. Historical member balance carried forward from prior records; corresponding club asset not independently reconciled."></textarea>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-4">
                            <button type="submit" class="btn btn-primary">Record Balance Brought Forward</button>
                            <a href="<?= $base ?>?page=savings-account-view&id=<?= (int)$account['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>

                    <script>
                    (function () {
                        var select     = document.getElementById('bfPostingMode');
                        var amountEl   = document.getElementById('bfAmount');
                        var previewBox = document.getElementById('bfPreviewBox');
                        var previewTxt = document.getElementById('bfPreviewText');
                        var reasonBox  = document.getElementById('bfReasonBox');
                        var reasonText = document.getElementById('bfReasonText');

                        function fmt(n) {
                            n = parseFloat(n) || 0;
                            return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                        }

                        function update() {
                            var opt = select.options[select.selectedIndex];
                            var mode = select.value;
                            var amount = fmt(amountEl.value);

                            if (mode === 'verified_asset') {
                                var method = opt.getAttribute('data-method') || 'Selected Account';
                                previewTxt.innerHTML = 'Dr ' + method + ' — Shs ' + amount + '<br>Cr Member Savings Liability — Shs ' + amount;
                                previewBox.style.display = '';
                                reasonBox.style.display = 'none';
                                reasonText.required = false;
                            } else if (mode === 'historical_only') {
                                previewTxt.textContent = 'Historical Only — No Cash, Bank or Mobile Money asset will be created.';
                                previewBox.style.display = '';
                                reasonBox.style.display = '';
                                reasonText.required = true;
                            } else {
                                previewBox.style.display = 'none';
                                reasonBox.style.display = 'none';
                                reasonText.required = false;
                            }
                        }

                        select.addEventListener('change', update);
                        amountEl.addEventListener('input', update);
                        update();
                    })();
                    </script>
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
                        <li>If <strong>Historical Only</strong> is selected: no cash, bank, or mobile-money balance changes, and no General Ledger journal entry is created — a reason is required.</li>
                        <li>If <strong>Cash / Bank / Mobile Money</strong> is selected: a General Ledger journal entry is posted immediately — Dr the selected account, Cr Members' Savings Liability.</li>
                        <li>Only one Balance Brought Forward is allowed per account. If entered incorrectly, it must be reversed (not edited) before a corrected one can be recorded.</li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
