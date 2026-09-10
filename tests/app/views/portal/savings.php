<?php $accountModel = new MemberSavingsAccountModel(); ?>
<h3 class="fw-bold mb-3"><i class="bi bi-piggy-bank me-2"></i>My Savings</h3>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-4">
        <div class="stat-card stat-card-accent">
            <div class="stat-label">Total Balance</div>
            <div class="stat-value">Shs <?= number_format($balance, 2) ?></div>
        </div>
    </div>
    <div class="col-6 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Total Deposits Made</div>
            <div class="stat-value" style="font-size:1.2rem"><?= (int)$depositCount ?></div>
        </div>
    </div>
    <div class="col-12 col-md-4">
        <div class="stat-card">
            <div class="stat-label">Savings Accounts</div>
            <div class="stat-value" style="font-size:1.2rem"><?= count($accounts) ?></div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold">My Accounts</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Account Number</th><th>Type</th><th class="text-end">Balance</th><th class="text-center">Status</th></tr></thead>
            <tbody>
                <?php if (empty($accounts)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No savings accounts on record.</td></tr>
                <?php else: foreach ($accounts as $a): ?>
                <tr>
                    <td><?= htmlspecialchars($a['account_number']) ?></td>
                    <td class="text-capitalize"><?= htmlspecialchars(str_replace('_', ' ', $a['account_type'])) ?></td>
                    <td class="text-end">Shs <?= number_format($accountModel->getAccountBalance((int)$a['id']), 2) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $a['status'] === 'active' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= htmlspecialchars(ucfirst($a['status'])) ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Transaction History</h6></div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Receipt</th><th>Type</th><th>Method</th><th class="text-end">Amount</th><th class="text-end">Balance</th></tr></thead>
            <tbody>
                <?php if (empty($history)): ?>
                <tr><td colspan="6" class="text-center text-muted py-3">No transactions on record.</td></tr>
                <?php else: foreach ($history as $t): ?>
                <tr>
                    <td class="small text-muted"><?= date('d M Y', strtotime($t['transaction_date'])) ?></td>
                    <td class="small"><?= htmlspecialchars($t['receipt_number'] ?? '—') ?></td>
                    <td class="text-capitalize small"><?= htmlspecialchars(str_replace('_', ' ', $t['transaction_type'])) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($t['payment_method'] ?? '—') ?></td>
                    <td class="text-end <?= (float)$t['amount'] < 0 ? 'text-danger' : 'text-success' ?>">
                        <?= (float)$t['amount'] < 0 ? '-' : '+' ?>Shs <?= number_format(abs((float)$t['amount']), 2) ?>
                    </td>
                    <td class="text-end small">Shs <?= number_format((float)($t['running_balance'] ?? 0), 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
