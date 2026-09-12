<?php
$pageTitle = $pageTitle ?? 'Disburse Loan';
$base = APP_URL . '/index.php';
$memberLabel = $member ? trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? '')) . ' (' . ($member['member_number'] ?? '') . ')' : ('member #' . $loan['member_id']);
?>

<?php require VIEW_PATH . '/layouts/page-title.php'; ?>

<?php if ($msg = Session::flash('error')): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-12 col-lg-7">
        <div class="card">
            <div class="card-header"><i class="bi bi-cash-coin me-2"></i>Loan Disbursement — <?= htmlspecialchars($loan['loan_number']) ?></div>
            <div class="card-body">
                <div class="alert alert-secondary small mb-4">
                    <div class="d-flex justify-content-between"><span>Loan</span><strong><?= htmlspecialchars($loan['loan_number']) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Member</span><strong><?= htmlspecialchars($memberLabel) ?></strong></div>
                    <div class="d-flex justify-content-between"><span>Approved Amount</span><strong>Shs <?= number_format((float)$loan['loan_amount'], 2) ?></strong></div>
                </div>

                <form method="POST" action="<?= $base ?>?page=loan-disburse" id="loanDisburseForm">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="loan_id" value="<?= (int)$loan['id'] ?>">

                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label">Amount to Disburse (Shs)</label>
                            <input type="text" class="form-control" value="<?= number_format((float)$loan['loan_amount'], 2) ?>" disabled>
                            <div class="form-text">The approved principal only — never interest, fees, or the total payable.</div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Disbursement Date</label>
                            <input type="text" class="form-control" value="<?= date('d M Y') ?>" disabled>
                        </div>
                        <div class="col-12">
                            <label class="form-label mb-1">Funding Source</label>
                            <div class="form-text mt-0 mb-2">Where is this loan actually being paid out from? This determines the accounting entry.</div>
                            <select name="disbursement_method" id="loanDisburseMethod" class="form-select" required>
                                <option value="">Select…</option>
                                <?php foreach ($methods as $method): ?>
                                <option value="<?= htmlspecialchars($method) ?>"><?= htmlspecialchars($method) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12" id="loanDisbursePreviewBox" style="display:none;">
                            <div class="alert alert-info small mb-0" id="loanDisbursePreviewText"></div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 mt-4">
                        <button type="submit" class="btn btn-success" id="loanDisburseSubmit">Confirm Disbursement</button>
                        <a href="<?= $base ?>?page=loan-view&id=<?= (int)$loan['id'] ?>" class="btn btn-secondary">Cancel</a>
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
                    <li>This posts a General Ledger journal immediately — Dr Member Loan Receivable, Cr the selected funding account — and moves the loan to <strong>Active</strong>.</li>
                    <li>Only the approved principal is disbursed; interest and processing fee are not part of this posting.</li>
                    <li>This action can only be performed once per loan — a loan already disbursed cannot be disbursed again.</li>
                    <li>The disbursement is recorded in the activity log: who, when, how much, and which funding source.</li>
                </ul>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var select     = document.getElementById('loanDisburseMethod');
    var previewBox = document.getElementById('loanDisbursePreviewBox');
    var previewTxt = document.getElementById('loanDisbursePreviewText');
    var form       = document.getElementById('loanDisburseForm');
    var amount     = <?= (float)$loan['loan_amount'] ?>;

    function fmt(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

    function update() {
        var method = select.value;
        if (method) {
            previewTxt.innerHTML = 'Dr Member Loan Receivable — Shs ' + fmt(amount) + '<br>Cr ' + method + ' — Shs ' + fmt(amount);
            previewBox.style.display = '';
        } else {
            previewBox.style.display = 'none';
        }
    }

    form.addEventListener('submit', function (e) {
        var method = select.value;
        if (!method) { e.preventDefault(); return; }
        if (!confirm('Disburse this loan (Shs ' + fmt(amount) + ') via ' + method + '? This posts the accounting entry and cannot be undone from this screen.')) {
            e.preventDefault();
        }
    });

    select.addEventListener('change', update);
    update();
})();
</script>
