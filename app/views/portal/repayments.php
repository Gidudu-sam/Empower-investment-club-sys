<h3 class="fw-bold mb-3"><i class="bi bi-receipt me-2"></i>My Repayments</h3>

<div class="stat-card stat-card-accent mb-4" style="max-width:280px;">
    <div class="stat-label">Total Paid</div>
    <div class="stat-value">Shs <?= number_format((float)$totalPaid, 2) ?></div>
</div>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Date</th><th>Receipt</th><th>Loan</th><th class="text-end">Amount Paid</th></tr></thead>
            <tbody>
                <?php if (empty($repayments)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No repayments on record.</td></tr>
                <?php else: foreach ($repayments as $r): ?>
                <tr>
                    <td class="small"><?= date('d M Y', strtotime($r['payment_date'])) ?></td>
                    <td class="small"><?= htmlspecialchars($r['repayment_number'] ?? '—') ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($r['loan_number']) ?></td>
                    <td class="text-end">Shs <?= number_format((float)$r['amount_paid'], 2) ?></td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
