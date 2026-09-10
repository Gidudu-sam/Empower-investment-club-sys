<h3 class="fw-bold mb-3"><i class="bi bi-tag me-2"></i>My Fees</h3>

<div class="card">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr><th>Fee</th><th>Charged</th><th class="text-end">Amount</th><th class="text-center">Status</th></tr></thead>
            <tbody>
                <?php if (empty($fees)): ?>
                <tr><td colspan="4" class="text-center text-muted py-3">No fee charges on record.</td></tr>
                <?php else: foreach ($fees as $f): ?>
                <tr>
                    <td><?= htmlspecialchars($f['fee_name']) ?></td>
                    <td class="small text-muted"><?= !empty($f['charged_date']) ? date('d M Y', strtotime($f['charged_date'])) : '—' ?></td>
                    <td class="text-end">Shs <?= number_format((float)$f['amount'], 2) ?></td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= match($f['status']) {
                            'paid' => 'bg-success-subtle text-success',
                            'waived' => 'bg-info-subtle text-info',
                            'cancelled' => 'bg-secondary-subtle text-secondary',
                            default => 'bg-warning-subtle text-warning'
                        } ?>"><?= htmlspecialchars(ucfirst($f['status'])) ?></span>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
