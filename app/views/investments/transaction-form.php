<?php
$pageTitle = $pageTitle ?? 'Record Investment Transaction';
$title = $pageTitle;
$icon  = 'bi-arrow-left-right';
$base = APP_URL . '/index.php';
$carrying = (float)$investment['carrying_amount'];
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if (Session::has('error')): ?>
        <div class="alert alert-danger"><?= Session::flash('error') ?></div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-12 col-lg-7">
            <div class="card">
                <div class="card-header">
                    <i class="bi bi-arrow-left-right me-2"></i>Record Transaction — <?= htmlspecialchars($investment['investment_number']) ?>
                </div>
                <div class="card-body">
                    <div class="alert alert-info small">
                        Current carrying amount: <strong>Shs <?= number_format($carrying, 2) ?></strong>.
                        This transaction posts to the ledger immediately once submitted.
                    </div>

                    <form method="POST" action="<?= $base ?>?page=investment-transaction-store" id="txnForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                        <input type="hidden" name="investment_id" value="<?= $investment['id'] ?>">

                        <div class="mb-3">
                            <label class="form-label">Transaction Type</label>
                            <select name="transaction_type" id="txnType" class="form-select" required onchange="updatePreview()">
                                <option value="">Select Type</option>
                                <option value="income">Investment Income</option>
                                <option value="withdrawal">Partial Withdrawal</option>
                                <option value="disposal">Disposal / Maturity (full)</option>
                            </select>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Amount (Shs)</label>
                                <input type="number" step="0.01" min="0.01" name="amount" id="txnAmount" class="form-control" required oninput="updatePreview()">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Transaction Date</label>
                                <input type="date" name="transaction_date" class="form-control" required min="<?= htmlspecialchars($investment['start_date']) ?>">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Funding Account (Cash/Bank)</label>
                            <select name="funding_account_id" class="form-select" required>
                                <option value="">Select Account</option>
                                <?php foreach ($accounts as $a): ?>
                                    <?php if ($a['type'] === 'asset'): ?>
                                    <option value="<?= $a['id'] ?>"><?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?></option>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea name="description" class="form-control" rows="2"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-check-circle me-1"></i> Record &amp; Post
                            </button>
                            <a href="<?= $base ?>?page=investment-view&id=<?= $investment['id'] ?>" class="btn btn-secondary">Cancel</a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-5">
            <div class="card">
                <div class="card-header"><i class="bi bi-eye me-2"></i>Accounting Impact Preview</div>
                <div class="card-body">
                    <p class="text-muted small">This is an estimate based on the current carrying amount. The actual posting is validated again at save time.</p>
                    <div id="preview" class="small">
                        <em class="text-muted">Select a transaction type and enter an amount to preview.</em>
                    </div>
                </div>
            </div>
        </div>
    </div>

<script>
const carryingAmount = <?= json_encode($carrying) ?>;

function updatePreview() {
    const type = document.getElementById('txnType').value;
    const amount = parseFloat(document.getElementById('txnAmount').value) || 0;
    const el = document.getElementById('preview');

    if (!type || amount <= 0) {
        el.innerHTML = '<em class="text-muted">Select a transaction type and enter an amount to preview.</em>';
        return;
    }

    const fmt = n => 'Shs ' + n.toLocaleString(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let rows = [];

    if (type === 'income') {
        rows = [
            ['Dr', 'Funding Account', amount],
            ['Cr', 'Investment Income', amount],
        ];
    } else if (type === 'withdrawal') {
        if (amount > carryingAmount + 0.01) {
            el.innerHTML = '<div class="alert alert-danger mb-0">Amount exceeds the remaining carrying amount (' + fmt(carryingAmount) + ') — this will be rejected.</div>';
            return;
        }
        rows = [
            ['Dr', 'Funding Account', amount],
            ['Cr', 'Investment Asset Account', amount],
        ];
    } else if (type === 'disposal') {
        if (Math.abs(amount - carryingAmount) < 0.01) {
            rows = [
                ['Dr', 'Funding Account', amount],
                ['Cr', 'Investment Asset Account', carryingAmount],
            ];
        } else if (amount > carryingAmount) {
            const gain = amount - carryingAmount;
            rows = [
                ['Dr', 'Funding Account', amount],
                ['Cr', 'Investment Asset Account', carryingAmount],
                ['Cr', 'Investment Gain', gain],
            ];
        } else {
            const loss = carryingAmount - amount;
            rows = [
                ['Dr', 'Funding Account', amount],
                ['Dr', 'Loss on Investment Disposal', loss],
                ['Cr', 'Investment Asset Account', carryingAmount],
            ];
        }
    }

    let html = '<div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th></th><th>Account</th><th class="text-end">Amount</th></tr></thead><tbody>';
    rows.forEach(r => {
        html += '<tr><td><strong>' + r[0] + '</strong></td><td>' + r[1] + '</td><td class="text-end">' + fmt(r[2]) + '</td></tr>';
    });
    html += '</tbody></table></div>';
    el.innerHTML = html;
}
</script>
