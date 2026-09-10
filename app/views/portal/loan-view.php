<?php $base = APP_URL . '/index.php'; ?>
<a href="<?= $base ?>?page=portal-loans" class="text-decoration-none small text-muted d-inline-block mb-2">
    <i class="bi bi-arrow-left me-1"></i>Back to My Loans
</a>
<h3 class="fw-bold mb-3"><?= htmlspecialchars($loan['loan_number']) ?></h3>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="stat-card"><div class="stat-label">Loan Amount</div><div class="stat-value" style="font-size:1.1rem">Shs <?= number_format((float)$loan['loan_amount'], 2) ?></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card"><div class="stat-label">Outstanding</div><div class="stat-value" style="font-size:1.1rem">Shs <?= number_format((float)$loan['outstanding'], 2) ?></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card"><div class="stat-label">Status</div><div class="stat-value text-capitalize" style="font-size:1.1rem"><?= htmlspecialchars(str_replace('_',' ',$loan['status'])) ?></div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="stat-card"><div class="stat-label">Due Date</div><div class="stat-value" style="font-size:1.1rem"><?= !empty($loan['due_date']) ? date('d M Y', strtotime($loan['due_date'])) : '—' ?></div></div>
    </div>
</div>

<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#scheduleTab">Payment Schedule</button></li>
    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#historyTab">Payment History</button></li>
</ul>
<div class="tab-content">
    <div class="tab-pane fade show active" id="scheduleTab">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>#</th><th>Due Date</th><th class="text-end">Amount Due</th><th class="text-center">Status</th></tr></thead>
                    <tbody>
                        <?php if (empty($installments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No schedule on record.</td></tr>
                        <?php else: foreach ($installments as $i => $inst): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td class="small"><?= !empty($inst['due_date']) ? date('d M Y', strtotime($inst['due_date'])) : '—' ?></td>
                            <td class="text-end">Shs <?= number_format((float)($inst['amount_due'] ?? $inst['installment_amount'] ?? 0), 2) ?></td>
                            <td class="text-center">
                                <span class="badge rounded-pill <?= ($inst['status'] ?? '') === 'paid' ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                                    <?= htmlspecialchars(ucfirst($inst['status'] ?? 'pending')) ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="tab-pane fade" id="historyTab">
        <div class="card">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead><tr><th>Date</th><th>Receipt</th><th class="text-end">Amount Paid</th><th class="text-end">Balance After</th></tr></thead>
                    <tbody>
                        <?php if (empty($repayments)): ?>
                        <tr><td colspan="4" class="text-center text-muted py-3">No payments recorded yet.</td></tr>
                        <?php else: foreach ($repayments as $r): ?>
                        <tr>
                            <td class="small"><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                            <td class="small"><?= htmlspecialchars($r['repayment_number'] ?? '—') ?></td>
                            <td class="text-end">Shs <?= number_format((float)$r['amount_paid'], 2) ?></td>
                            <td class="text-end small">Shs <?= number_format((float)($r['balance_after'] ?? 0), 2) ?></td>
                        </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
