<?php
$pageTitle = $pageTitle ?? 'Classify Balance Brought Forward';
$base = APP_URL . '/index.php';
$memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) . ' (' . ($member['member_number'] ?? '') . ')' : ('member #' . $bfRow['member_id']);
?>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if ($msg = Session::flash('error')): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-patch-question me-2"></i>Classify Balance Brought Forward — <?= htmlspecialchars($bfRow['receipt_number']) ?></div>
            <div class="card-body">
                <div class="alert alert-warning small mb-3">
                    <strong>Status: Unclassified — Classification Required.</strong>
                    Choose the option that reflects reality — do not guess. If the club does not currently hold
                    verified funds behind this balance, choose Historical Only.
                </div>

                <div class="alert alert-secondary small mb-4">
                    <div class="d-flex justify-content-between"><span>Member</span><strong><?= htmlspecialchars($memberLabel) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Savings Account</span><strong><?= htmlspecialchars(($account['account_number'] ?? '') . ' (' . ucfirst($account['account_type'] ?? '') . ')') ?></strong></div>
                    <div class="d-flex justify-content-between"><span>B/F Amount</span><strong>Shs <?= number_format((float)$bfRow['credit'], 2) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Effective / As-of Date</span><strong><?= date('d M Y', strtotime($bfRow['transaction_date'])) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Original Entry Date</span><strong><?= date('d M Y H:i', strtotime($bfRow['created_at'])) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Current Posting Mode</span><strong>Unclassified</strong></div>
                    <div class="d-flex justify-content-between"><span>Current Asset Account</span><strong>None</strong></div>
                    <div class="mt-2"><span class="d-block mb-1">Historical Period / Notes</span><strong class="d-block"><?= htmlspecialchars($bfRow['notes'] ?? '') ?></strong></div>
                    <div class="d-flex justify-content-between mt-2"><span>Recorded By</span><strong>user #<?= (int)$bfRow['recorded_by'] ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Receipt</span><strong><?= htmlspecialchars($bfRow['receipt_number']) ?></strong></div>
                </div>

                <form method="POST" action="<?= $base ?>?page=savings-account-bf-classify-store" id="bfClassifyForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="savings_id" value="<?= (int)$bfRow['id'] ?>">

                    <div class="mb-3">
                        <label class="form-label mb-1">Classification</label>
                        <div class="form-text mt-0 mb-2">Does the club currently hold real funds backing this balance?</div>
                        <select name="posting_mode" id="bfClassifyMode" class="form-select" required>
                            <option value="">Select…</option>
                            <?php foreach ($bfAssetMethods as $method): ?>
                            <option value="verified_asset" data-method="<?= htmlspecialchars($method) ?>">Verified <?= htmlspecialchars($method) ?> — funds confirmed held by the club</option>
                            <?php endforeach; ?>
                            <option value="historical_only">Historical Only — asset not independently verified</option>
                        </select>
                    </div>

                    <div class="mb-3" id="bfClassifyPreviewBox" style="display:none;">
                        <div class="alert alert-secondary small mb-0" id="bfClassifyPreviewText"></div>
                    </div>

                    <div class="mb-3" id="bfClassifyReasonBox" style="display:none;">
                        <label class="form-label">Reason (why the corresponding club asset has not been independently verified)</label>
                        <textarea name="historical_only_reason" id="bfClassifyReasonText" class="form-control" rows="2" placeholder="e.g. Historical member balance carried forward from prior records; corresponding club asset not independently reconciled."></textarea>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" id="bfClassifySubmit">Confirm Classification</button>
                        <a href="<?= $base ?>?page=savings-account-bf-register" class="btn btn-secondary">Cancel</a>
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
                    <li>This action can only be performed <strong>once</strong> per record — a classified B/F cannot be classified again.</li>
                    <li>If <strong>Verified Cash/Bank/Mobile Money</strong> is chosen: a General Ledger journal entry is posted immediately (Dr the selected account, Cr Members' Savings Liability) and the entry becomes a normal posted transaction, correctable only via the Controlled Corrections workflow from that point on.</li>
                    <li>If <strong>Historical Only</strong> is chosen: no Cash, Bank, or Mobile Money balance changes, and no journal entry is created — a reason is required and is added to this record's notes.</li>
                    <li>The classification action is recorded in the activity log: who, when, what was chosen, and the resulting journal reference if any.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var select     = document.getElementById('bfClassifyMode');
    var previewBox = document.getElementById('bfClassifyPreviewBox');
    var previewTxt = document.getElementById('bfClassifyPreviewText');
    var reasonBox  = document.getElementById('bfClassifyReasonBox');
    var reasonText = document.getElementById('bfClassifyReasonText');
    var form       = document.getElementById('bfClassifyForm');
    var amount     = <?= (float)$bfRow['credit'] ?>;

    function fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function update() {
        var opt = select.options[select.selectedIndex];
        var mode = select.value;
        if (mode === 'verified_asset') {
            var method = opt.getAttribute('data-method') || 'Selected Account';
            previewTxt.innerHTML = 'Dr ' + method + ' — Shs ' + fmt(amount) + '<br>Cr Member Savings Liability — Shs ' + fmt(amount) + '<br><em>This will create a GL opening transaction.</em>';
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

    form.addEventListener('submit', function (e) {
        var opt = select.options[select.selectedIndex];
        var mode = select.value;
        var label = mode === 'verified_asset' ? ('Verified ' + (opt.getAttribute('data-method') || '')) : 'Historical Only';
        if (!confirm('Classify this Balance Brought Forward (Shs ' + fmt(amount) + ') as ' + label + '? This cannot be undone through ordinary editing.')) {
            e.preventDefault();
        }
    });

    select.addEventListener('change', update);
    update();
})();
</script>
