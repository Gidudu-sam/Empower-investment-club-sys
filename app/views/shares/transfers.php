<?php
/**
 * Share Transfers View
 * Internal transfers between member Savings and Share accounts
 */
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <div>
                    <h2 class="mb-1">Share Transfers</h2>
                    <p class="text-muted mb-0">Transfer funds between Savings and Share accounts</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Transfer Form Card -->
    <div class="row mb-4">
        <div class="col-lg-8">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">New Transfer</h5>
                </div>
                <div class="card-body">
                    <!-- Member Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">Select Member</label>
                        <select class="form-select" id="memberSelect">
                            <option value="">-- Select Member --</option>
                            <?php
                            $memberModel = new MemberModel();
                            $members = $memberModel->getAllActive();
                            foreach ($members as $member):
                            ?>
                                <option value="<?= htmlspecialchars($member['id']) ?>">
                                    <?= htmlspecialchars($member['member_number']) ?> - 
                                    <?= htmlspecialchars($member['first_name'] . ' ' . $member['last_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Member Accounts Display -->
                    <div id="accountsSection" style="display: none;">
                        <div class="alert alert-info mb-4">
                            <div class="d-flex align-items-center">
                                <i class="bi bi-info-circle me-2"></i>
                                <div>
                                    <strong>Member:</strong> <span id="memberName"></span>
                                    <span class="ms-3"><strong>Member #:</strong> <span id="memberNumber"></span></span>
                                </div>
                            </div>
                        </div>

                        <!-- Transfer Direction Tabs -->
                        <ul class="nav nav-tabs mb-4" id="transferTabs" role="tablist">
                            <li class="nav-item" role="presentation">
                                <button class="nav-link active" id="savings-to-shares-tab" data-bs-toggle="tab" 
                                        data-bs-target="#savingsToShares" type="button" role="tab">
                                    <i class="bi bi-arrow-right-circle me-2"></i>Savings → Shares
                                </button>
                            </li>
                            <li class="nav-item" role="presentation">
                                <button class="nav-link" id="shares-to-savings-tab" data-bs-toggle="tab" 
                                        data-bs-target="#sharesToSavings" type="button" role="tab">
                                    <i class="bi bi-arrow-left-circle me-2"></i>Shares → Savings
                                </button>
                            </li>
                        </ul>

                        <!-- Tab Content -->
                        <div class="tab-content" id="transferTabContent">
                            <!-- Savings to Shares Tab -->
                            <div class="tab-pane fade show active" id="savingsToShares" role="tabpanel">
                                <form id="savingsToSharesForm">
                                    <input type="hidden" name="csrf_token" value="<?= Session::generateCSRF() ?>">
                                    <input type="hidden" name="member_id" id="sts_member_id">
                                    <input type="hidden" name="share_account_id" id="sts_share_account_id">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">From: Savings Account</label>
                                            <select class="form-select" name="savings_account_id" id="sts_savings_account" required>
                                                <option value="">-- Select Account --</option>
                                            </select>
                                            <small class="text-muted">Balance: <span id="sts_savings_balance">UGX 0</span></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">To: Share Account</label>
                                            <input type="text" class="form-control" id="sts_share_account_display" readonly>
                                            <small class="text-muted">Balance: <span id="sts_share_balance">UGX 0</span></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Amount (UGX)</label>
                                            <input type="number" class="form-control" name="amount" id="sts_amount" 
                                                   step="0.01" min="1" required placeholder="0.00">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Narration</label>
                                            <textarea class="form-control" name="narration" id="sts_narration" 
                                                      rows="2" required placeholder="Reason for transfer"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-arrow-right-circle me-2"></i>Transfer to Shares
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>

                            <!-- Shares to Savings Tab -->
                            <div class="tab-pane fade" id="sharesToSavings" role="tabpanel">
                                <form id="sharesToSavingsForm">
                                    <input type="hidden" name="csrf_token" value="<?= Session::generateCSRF() ?>">
                                    <input type="hidden" name="member_id" id="sst_member_id">
                                    <input type="hidden" name="share_account_id" id="sst_share_account_id">

                                    <div class="row g-3">
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">From: Share Account</label>
                                            <input type="text" class="form-control" id="sst_share_account_display" readonly>
                                            <small class="text-muted">Balance: <span id="sst_share_balance">UGX 0</span></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">To: Savings Account</label>
                                            <select class="form-select" name="savings_account_id" id="sst_savings_account" required>
                                                <option value="">-- Select Account --</option>
                                            </select>
                                            <small class="text-muted">Balance: <span id="sst_savings_balance">UGX 0</span></small>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label fw-semibold">Amount (UGX)</label>
                                            <input type="number" class="form-control" name="amount" id="sst_amount" 
                                                   step="0.01" min="1" required placeholder="0.00">
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label fw-semibold">Narration</label>
                                            <textarea class="form-control" name="narration" id="sst_narration" 
                                                      rows="2" required placeholder="Reason for transfer"></textarea>
                                        </div>
                                        <div class="col-12">
                                            <button type="submit" class="btn btn-success">
                                                <i class="bi bi-arrow-left-circle me-2"></i>Transfer to Savings
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Info Card -->
        <div class="col-lg-4">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h6 class="mb-0">Transfer Information</h6>
                </div>
                <div class="card-body">
                    <div class="small">
                        <p><strong>Transfer Types:</strong></p>
                        <ul class="mb-3">
                            <li><strong>Savings → Shares:</strong> Convert savings to share capital</li>
                            <li><strong>Shares → Savings:</strong> Redeem shares back to savings</li>
                        </ul>

                        <p><strong>Requirements:</strong></p>
                        <ul class="mb-3">
                            <li>Both accounts must belong to the same member</li>
                            <li>Sufficient balance required</li>
                            <li>Amount must be greater than zero</li>
                            <li>Narration/reason required</li>
                        </ul>

                        <div class="alert alert-warning small mb-0">
                            <i class="bi bi-exclamation-triangle me-2"></i>
                            <strong>Note:</strong> Transfers are immediate and cannot be edited. 
                            A journal entry will be created automatically.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transfers -->
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white border-bottom py-3">
                    <h5 class="mb-0">Recent Transfers</h5>
                </div>
                <div class="card-body">
                    <div id="transferHistory">
                        <p class="text-muted text-center py-4">Select a member to view transfer history</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Success Modal -->
<div class="modal fade" id="successModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-success text-white">
                <h5 class="modal-title"><i class="bi bi-check-circle me-2"></i>Transfer Successful</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p><strong>Reference:</strong> <span id="successReference"></span></p>
                <p><strong>Amount:</strong> UGX <span id="successAmount"></span></p>
                <p><strong>Journal Entry:</strong> <span id="successJournal"></span></p>
                <p class="mb-0"><strong>Type:</strong> <span id="successType"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const memberSelect = document.getElementById('memberSelect');
    const accountsSection = document.getElementById('accountsSection');
    const savingsToSharesForm = document.getElementById('savingsToSharesForm');
    const sharesToSavingsForm = document.getElementById('sharesToSavingsForm');

    let currentMemberData = null;

    // Load member accounts when selected
    memberSelect.addEventListener('change', async function() {
        const memberId = this.value;
        
        if (!memberId) {
            accountsSection.style.display = 'none';
            return;
        }

        try {
            const response = await fetch(`?page=share-transfer-get-accounts&member_id=${memberId}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message);
            }

            currentMemberData = data;

            // Update member info
            document.getElementById('memberName').textContent = data.member.name;
            document.getElementById('memberNumber').textContent = data.member.member_number;

            // Populate savings accounts
            populateSavingsAccounts(data.savings_accounts);

            // Populate share account
            populateShareAccount(data.share_account);

            // Show accounts section
            accountsSection.style.display = 'block';

            // Load transfer history
            loadTransferHistory(memberId);

        } catch (error) {
            alert('Error loading member accounts: ' + error.message);
        }
    });

    function populateSavingsAccounts(accounts) {
        const stsSavings = document.getElementById('sts_savings_account');
        const sstSavings = document.getElementById('sst_savings_account');

        stsSavings.innerHTML = '<option value="">-- Select Account --</option>';
        sstSavings.innerHTML = '<option value="">-- Select Account --</option>';

        accounts.forEach(acc => {
            if (acc.status === 'active') {
                const option = `<option value="${acc.id}" data-balance="${acc.balance}">
                    ${acc.account_number} (${acc.account_type}) - UGX ${formatNumber(acc.balance)}
                </option>`;
                stsSavings.innerHTML += option;
                sstSavings.innerHTML += option;
            }
        });

        // Update balance display when account selected
        stsSavings.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const balance = selected.dataset.balance || 0;
            document.getElementById('sts_savings_balance').textContent = 'UGX ' + formatNumber(balance);
        });

        sstSavings.addEventListener('change', function() {
            const selected = this.options[this.selectedIndex];
            const balance = selected.dataset.balance || 0;
            document.getElementById('sst_savings_balance').textContent = 'UGX ' + formatNumber(balance);
        });
    }

    function populateShareAccount(account) {
        if (!account) {
            alert('Member does not have a share account!');
            accountsSection.style.display = 'none';
            return;
        }

        // Set values for both forms
        document.getElementById('sts_member_id').value = currentMemberData.member.id;
        document.getElementById('sst_member_id').value = currentMemberData.member.id;

        document.getElementById('sts_share_account_id').value = account.id;
        document.getElementById('sst_share_account_id').value = account.id;

        document.getElementById('sts_share_account_display').value = account.account_number;
        document.getElementById('sst_share_account_display').value = account.account_number;

        document.getElementById('sts_share_balance').textContent = 'UGX ' + formatNumber(account.balance);
        document.getElementById('sst_share_balance').textContent = 'UGX ' + formatNumber(account.balance);
    }

    // Handle Savings to Shares form submission
    savingsToSharesForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!confirm('Confirm transfer from Savings to Shares?')) {
            return;
        }

        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

        try {
            const response = await fetch('?page=share-transfer-savings-to-shares', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message);
            }

            showSuccessModal(data.data);
            this.reset();
            memberSelect.dispatchEvent(new Event('change')); // Reload accounts

        } catch (error) {
            alert('Transfer failed: ' + error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-arrow-right-circle me-2"></i>Transfer to Shares';
        }
    });

    // Handle Shares to Savings form submission
    sharesToSavingsForm.addEventListener('submit', async function(e) {
        e.preventDefault();

        if (!confirm('Confirm transfer from Shares to Savings?')) {
            return;
        }

        const formData = new FormData(this);
        const submitBtn = this.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Processing...';

        try {
            const response = await fetch('?page=share-transfer-shares-to-savings', {
                method: 'POST',
                body: formData
            });

            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message);
            }

            showSuccessModal(data.data);
            this.reset();
            memberSelect.dispatchEvent(new Event('change')); // Reload accounts

        } catch (error) {
            alert('Transfer failed: ' + error.message);
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="bi bi-arrow-left-circle me-2"></i>Transfer to Savings';
        }
    });

    function showSuccessModal(data) {
        document.getElementById('successReference').textContent = data.reference_number;
        document.getElementById('successAmount').textContent = formatNumber(data.amount);
        document.getElementById('successJournal').textContent = data.journal_entry_number;
        document.getElementById('successType').textContent = data.type === 'savings_to_shares' 
            ? 'Savings → Shares' : 'Shares → Savings';

        const modal = new bootstrap.Modal(document.getElementById('successModal'));
        modal.show();
    }

    async function loadTransferHistory(memberId) {
        const historyDiv = document.getElementById('transferHistory');
        historyDiv.innerHTML = '<p class="text-center"><span class="spinner-border spinner-border-sm"></span> Loading...</p>';

        try {
            const response = await fetch(`?page=share-transfer-history&member_id=${memberId}`);
            const data = await response.json();

            if (!data.success) {
                throw new Error(data.message);
            }

            if (data.transfers.length === 0) {
                historyDiv.innerHTML = '<p class="text-muted text-center py-4">No transfers found</p>';
                return;
            }

            let html = '<div class="table-responsive"><table class="table table-sm table-hover">';
            html += '<thead><tr><th>Date</th><th>Reference</th><th>Type</th><th>Amount</th><th>Journal</th></tr></thead><tbody>';

            data.transfers.forEach(t => {
                const type = t.transaction_type === 'transfer_in' ? 'Savings → Shares' : 'Shares → Savings';
                const badge = t.transaction_type === 'transfer_in' ? 'success' : 'info';
                html += `<tr>
                    <td>${t.transaction_date}</td>
                    <td>${t.reference_number}</td>
                    <td><span class="badge bg-${badge}">${type}</span></td>
                    <td>UGX ${formatNumber(Math.abs(t.amount))}</td>
                    <td>${t.journal_entry_number || '-'}</td>
                </tr>`;
            });

            html += '</tbody></table></div>';
            historyDiv.innerHTML = html;

        } catch (error) {
            historyDiv.innerHTML = '<p class="text-danger">Error loading history: ' + error.message + '</p>';
        }
    }

    function formatNumber(num) {
        return parseFloat(num).toLocaleString('en-US', {minimumFractionDigits: 2, maximumFractionDigits: 2});
    }
});
</script>
