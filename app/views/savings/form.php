<?php
/**
 * Savings — Record / Edit Form
 */
$isEdit  = $formMode === 'edit';
$v       = fn(string $k, string $d='') => htmlspecialchars($saving[$k] ?? $d);
$err     = fn(string $k) => $errors[$k] ?? '';
$cls     = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$methods = ['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'];
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-<?= $isEdit ? 'pencil-square' : 'plus-circle-fill' ?> me-2 text-success"></i>
            <?= $isEdit ? 'Edit Savings Record' : 'Record Member Savings' ?>
        </h1>
        <p class="text-muted mb-0 small">
            Receipt: <strong class="text-success"><?= htmlspecialchars($receiptNumber) ?></strong>
        </p>
    </div>
    <a href="<?= APP_URL ?>/index.php?page=savings" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back to Savings
    </a>
</div>

<form id="savingsForm" method="POST" action="<?= $formAction ?>" novalidate>
    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

    <div class="row g-4">

        <!-- LEFT col -->
        <div class="col-lg-8">

            <!-- Member selection -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-person-circle text-success"></i>
                    <h6 class="mb-0 fw-semibold">Member</h6>
                </div>
                <div class="card-body p-4">
                    <?php if ($isEdit && $preselected): ?>
                    <!-- On edit: member is fixed -->
                    <input type="hidden" name="member_id" value="<?= (int)$saving['member_id'] ?>">
                    <div class="d-flex align-items-center gap-3 p-3 bg-light rounded-3">
                        <div class="member-avatar-sm <?= ($preselected['gender']??'')===''||$preselected['gender']==='Female' ? 'bg-pink':'bg-blue' ?>">
                            <?= strtoupper(substr($preselected['first_name'],0,1)) ?>
                        </div>
                        <div>
                            <div class="fw-semibold"><?= htmlspecialchars($preselected['first_name'].' '.$preselected['last_name']) ?></div>
                            <div class="text-muted small"><?= htmlspecialchars($preselected['member_number']) ?> · <?= htmlspecialchars($preselected['phone']) ?></div>
                        </div>
                        <span class="badge bg-success ms-auto">Selected</span>
                    </div>
                    <?php else: ?>
                    <!-- Search & select member -->
                    <input type="hidden" name="member_id" id="memberId"
                           value="<?= (int)($saving['member_id'] ?? $preselected['id'] ?? 0) ?>">

                    <div class="mb-2">
                        <label class="form-label fw-semibold" for="memberSearch">
                            Search Member <span class="text-danger">*</span>
                        </label>
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="memberSearch" class="form-control<?= $cls('member_id') ?>"
                                   placeholder="Type name, member number or phone…"
                                   autocomplete="off"
                                   value="<?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name'].' ('.$preselected['member_number'].')') : '' ?>">
                        </div>
                        <?php if ($err('member_id')): ?>
                        <div class="text-danger small mt-1"><?= htmlspecialchars($err('member_id')) ?></div>
                        <?php endif; ?>
                        <!-- Autocomplete dropdown -->
                        <div id="memberDropdown" class="list-group shadow mt-1"
                             style="position:absolute;z-index:1050;width:100%;max-width:420px;display:none;max-height:240px;overflow-y:auto;"></div>
                    </div>

                    <!-- Selected member card -->
                    <div id="selectedMember" class="<?= $preselected ? '' : 'd-none' ?> d-flex align-items-center gap-3 p-3 bg-light rounded-3 mt-2">
                        <div class="member-avatar-sm bg-blue" id="memberInitial">
                            <?= $preselected ? strtoupper(substr($preselected['first_name'],0,1)) : '?' ?>
                        </div>
                        <div>
                            <div class="fw-semibold" id="memberName">
                                <?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name']) : '' ?>
                            </div>
                            <div class="text-muted small" id="memberInfo">
                                <?= $preselected ? htmlspecialchars($preselected['member_number'].' · '.$preselected['phone']) : '' ?>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="clearMember">
                            <i class="bi bi-x"></i>
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Transaction details -->
            <div class="card mb-4">
                <div class="card-header d-flex align-items-center gap-2">
                    <i class="bi bi-cash-coin text-success"></i>
                    <h6 class="mb-0 fw-semibold">Transaction Details</h6>
                </div>
                <div class="card-body p-4">
                    <div class="row g-3">

                        <!-- Amount -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="amount">
                                Amount (Shs) <span class="text-danger">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text fw-bold">Shs</span>
                                <input type="number" id="amount" name="amount"
                                       class="form-control<?= $cls('amount') ?>"
                                       step="0.01" min="0.01"
                                       value="<?= $v('amount') ?>"
                                       placeholder="0.00" required>
                            </div>
                            <?php if ($err('amount')): ?>
                            <div class="text-danger small mt-1"><?= htmlspecialchars($err('amount')) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Payment Method -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="payment_method">
                                Payment Method <span class="text-danger">*</span>
                            </label>
                            <select id="payment_method" name="payment_method"
                                    class="form-select<?= $cls('payment_method') ?>" required>
                                <?php foreach ($methods as $pm): ?>
                                <option value="<?= $pm ?>"
                                    <?= ($v('payment_method','Cash')===$pm)?'selected':'' ?>>
                                    <?= $pm ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <?php if ($err('payment_method')): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($err('payment_method')) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Reference Number -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="reference_number">
                                Reference Number
                                <small class="text-muted fw-normal">(Mobile Money code, cheque no.)</small>
                            </label>
                            <input type="text" id="reference_number" name="reference_number"
                                   class="form-control"
                                   value="<?= $v('reference_number') ?>"
                                   placeholder="e.g. QA12BX9LKJ" maxlength="100">
                        </div>

                        <!-- Transaction Date -->
                        <div class="col-md-6">
                            <label class="form-label fw-semibold" for="transaction_date">
                                Transaction Date <span class="text-danger">*</span>
                            </label>
                            <input type="date" id="transaction_date" name="transaction_date"
                                   class="form-control<?= $cls('transaction_date') ?>"
                                   value="<?= $v('transaction_date', date('Y-m-d')) ?>"
                                   max="<?= date('Y-m-d') ?>" required>
                            <?php if ($err('transaction_date')): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($err('transaction_date')) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Notes -->
                        <div class="col-12">
                            <label class="form-label fw-semibold" for="notes">Notes</label>
                            <textarea id="notes" name="notes" rows="2"
                                      class="form-control"
                                      placeholder="Optional notes about this transaction…"><?= $v('notes') ?></textarea>
                        </div>

                    </div>
                </div>
            </div>

        </div><!-- /.col-lg-8 -->

        <!-- RIGHT col — summary -->
        <div class="col-lg-4">
            <div class="card mb-4 border-success border-opacity-25">
                <div class="card-header bg-success bg-opacity-10 d-flex align-items-center gap-2">
                    <i class="bi bi-receipt text-success"></i>
                    <h6 class="mb-0 fw-semibold text-success">Receipt Preview</h6>
                </div>
                <div class="card-body p-4">
                    <dl class="row mb-0 small">
                        <dt class="col-5 text-muted">Receipt No.</dt>
                        <dd class="col-7 fw-semibold text-success"><?= htmlspecialchars($receiptNumber) ?></dd>

                        <dt class="col-5 text-muted">Member</dt>
                        <dd class="col-7" id="previewMember">
                            <?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name']) : '—' ?>
                        </dd>

                        <dt class="col-5 text-muted">Amount</dt>
                        <dd class="col-7 fw-bold text-success fs-5 mb-0" id="previewAmount">
                            Shs <?= $v('amount') ? number_format((float)$saving['amount'],2) : '0.00' ?>
                        </dd>

                        <dt class="col-5 text-muted">Method</dt>
                        <dd class="col-7" id="previewMethod">
                            <?= $v('payment_method','Cash') ?>
                        </dd>

                        <dt class="col-5 text-muted">Date</dt>
                        <dd class="col-7" id="previewDate">
                            <?= $v('transaction_date') ? date('d M Y', strtotime($saving['transaction_date'])) : date('d M Y') ?>
                        </dd>
                    </dl>
                </div>
            </div>

            <!-- Action buttons -->
            <div class="d-flex flex-column gap-2">
                <button type="submit" id="submitBtn" class="btn btn-success w-100 fw-semibold py-2">
                    <i class="bi bi-<?= $isEdit ? 'floppy' : 'save' ?> me-2"></i>
                    <?= $isEdit ? 'Save Changes' : 'Record Savings' ?>
                </button>
                <button type="reset" class="btn btn-light w-100">
                    <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
                </button>
                <a href="<?= APP_URL ?>/index.php?page=savings" class="btn btn-outline-secondary w-100">
                    <i class="bi bi-x-lg me-1"></i>Cancel
                </a>
            </div>
        </div>

    </div>
</form>

<script>
(function(){
    'use strict';
    const form       = document.getElementById('savingsForm');
    const submitBtn  = document.getElementById('submitBtn');
    const amountEl   = document.getElementById('amount');
    const methodEl   = document.getElementById('payment_method');
    const dateEl     = document.getElementById('transaction_date');
    const memberIdEl = document.getElementById('memberId');
    const searchEl   = document.getElementById('memberSearch');
    const dropdown   = document.getElementById('memberDropdown');
    const selCard    = document.getElementById('selectedMember');
    const clearBtn   = document.getElementById('clearMember');

    // Live receipt preview
    if(amountEl) amountEl.addEventListener('input', function(){
        const v = parseFloat(this.value)||0;
        document.getElementById('previewAmount').textContent = 'Shs '+v.toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
    });
    if(methodEl) methodEl.addEventListener('change', function(){
        document.getElementById('previewMethod').textContent = this.value;
    });
    if(dateEl) dateEl.addEventListener('change', function(){
        if(this.value){
            const d = new Date(this.value);
            document.getElementById('previewDate').textContent =
                d.toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
        }
    });

    // Member autocomplete
    let searchTimer;
    if(searchEl) {
        searchEl.addEventListener('input', function(){
            clearTimeout(searchTimer);
            const q = this.value.trim();
            if(q.length < 2){ if(dropdown) dropdown.style.display='none'; return; }
            searchTimer = setTimeout(function(){
                fetch('<?= APP_URL ?>/index.php?page=savings-member-search&q='+encodeURIComponent(q))
                    .then(r=>r.json())
                    .then(data=>{
                        dropdown.innerHTML='';
                        if(!data.members||!data.members.length){
                            dropdown.style.display='none'; return;
                        }
                        data.members.forEach(m=>{
                            const a = document.createElement('a');
                            a.href='#';
                            a.className='list-group-item list-group-item-action py-2 px-3';
                            a.innerHTML = '<div class="fw-semibold small">'+m.full_name+'</div>'+
                                          '<div class="text-muted" style="font-size:.75rem">'+m.member_number+' · '+m.phone+'</div>';
                            a.addEventListener('click',function(e){
                                e.preventDefault();
                                selectMember(m);
                            });
                            dropdown.appendChild(a);
                        });
                        dropdown.style.display='block';
                    }).catch(()=>{});
            }, 300);
        });

        document.addEventListener('click', function(e){
            if(!searchEl.contains(e.target)) dropdown.style.display='none';
        });
    }

    function selectMember(m){
        if(memberIdEl) memberIdEl.value = m.id;
        if(searchEl)   searchEl.value  = m.full_name+' ('+m.member_number+')';
        if(dropdown)   dropdown.style.display='none';
        document.getElementById('previewMember').textContent = m.full_name;
        document.getElementById('memberInitial').textContent = m.full_name.charAt(0).toUpperCase();
        document.getElementById('memberName').textContent    = m.full_name;
        document.getElementById('memberInfo').textContent    = m.member_number+' · '+m.phone;
        if(selCard) selCard.classList.remove('d-none');
    }

    if(clearBtn) clearBtn.addEventListener('click', function(){
        if(memberIdEl) memberIdEl.value = '0';
        if(searchEl)   searchEl.value  = '';
        document.getElementById('previewMember').textContent = '—';
        if(selCard) selCard.classList.add('d-none');
    });

    // Submit validation
    form.addEventListener('submit', function(e){
        if(!form.checkValidity()||(memberIdEl&&parseInt(memberIdEl.value)<1)){
            e.preventDefault(); e.stopPropagation();
            if(memberIdEl&&parseInt(memberIdEl.value)<1 && searchEl){
                searchEl.classList.add('is-invalid');
            }
            const first = form.querySelector(':invalid');
            if(first) first.scrollIntoView({behavior:'smooth',block:'center'});
        } else {
            submitBtn.disabled=true;
            submitBtn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Saving…';
        }
        form.classList.add('was-validated');
    });
})();
</script>
