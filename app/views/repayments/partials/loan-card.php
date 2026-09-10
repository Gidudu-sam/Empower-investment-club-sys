<?php
/**
 * Inline loan summary card for the repayment form.
 * Variables available: $loanData (the full loan row with member details)
 */
if (!isset($loanData) || !$loanData) return;
?>
<div class="row g-2 small">
    <div class="col-sm-4">
        <span class="text-muted d-block">Loan No.</span>
        <strong style="color:var(--brand-navy)"><?= htmlspecialchars($loanData['loan_number']) ?></strong>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Member</span>
        <strong><?= htmlspecialchars($loanData['first_name'] . ' ' . $loanData['last_name']) ?></strong>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Status</span>
        <span class="badge <?= $loanData['status']==='overdue'?'bg-danger':'bg-success' ?> rounded-pill">
            <?= ucfirst($loanData['status']) ?>
        </span>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Loan Amount</span>
        <strong>Shs <?= number_format($loanData['loan_amount'],2) ?></strong>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Total Payable</span>
        <strong>Shs <?= number_format($loanData['total_payable'],2) ?></strong>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Outstanding Balance</span>
        <strong class="text-danger">Shs <?= number_format($loanData['outstanding'],2) ?></strong>
    </div>
    <div class="col-sm-4">
        <span class="text-muted d-block">Due Date</span>
        <strong><?= date('d M Y', strtotime($loanData['due_date'])) ?></strong>
    </div>
    <?php if (($outstandingPenalty ?? 0) > 0): ?>
    <div class="col-sm-4">
        <span class="text-muted d-block">Outstanding Penalty</span>
        <strong class="text-warning">Shs <?= number_format($outstandingPenalty, 2) ?></strong>
    </div>
    <?php endif; ?>
</div>
