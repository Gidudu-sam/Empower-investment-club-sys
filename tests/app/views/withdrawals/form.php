<?php
$base    = APP_URL . '/index.php';
$v       = fn(string $k, string $d='') => htmlspecialchars($withdrawal[$k] ?? $d);
$err     = fn(string $k) => $errors[$k] ?? '';
$cls     = fn(string $k) => isset($errors[$k]) ? ' is-invalid' : '';
$methods = ['Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other'];
$cPct    = $compulsoryPolicy ? (float)$compulsoryPolicy['maximum_withdrawal_percent'] : null;
$vPct    = $voluntaryPolicy  ? (float)$voluntaryPolicy['maximum_withdrawal_percent']  : null;
$initialType = ($withdrawal['withdrawal_type'] ?? '') === 'voluntary' ? 'voluntary' : 'annual_compulsory';
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-box-arrow-up-right me-2 text-danger"></i>Process Withdrawal
        </h1>
        <p class="text-muted mb-0 small">
            Number: <strong class="text-danger"><?= htmlspecialchars($wdlNumber) ?></strong>
            &nbsp;·&nbsp; Financial Year: <strong><?= $currentYear ?></strong>
        </p>
    </div>
    <a href="<?=$base?>?page=withdrawals" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Back
    </a>
</div>

<?php if (!empty($errors) && isset($errors[0])): ?>
<div class="alert alert-danger"><?= htmlspecialchars($errors[0]) ?></div>
<?php endif; ?>

<!-- Withdrawal Type Selector -->
<ul class="nav nav-pills mb-4" id="typeTabs">
    <li class="nav-item">
        <button type="button" class="nav-link active" data-type="annual_compulsory" id="tabCompulsory">
            <i class="bi bi-piggy-bank me-1"></i>Annual Compulsory
            <?php if ($cPct !== null): ?><span class="badge bg-light text-dark ms-1"><?= $cPct ?>% max</span><?php endif; ?>
        </button>
    </li>
    <li class="nav-item">
        <button type="button" class="nav-link" data-type="voluntary" id="tabVoluntary">
            <i class="bi bi-wallet2 me-1"></i>Voluntary
            <?php if ($vPct !== null): ?><span class="badge bg-light text-dark ms-1"><?= $vPct ?>% max</span><?php endif; ?>
        </button>
    </li>
</ul>

<?php if (!$compulsoryPolicy): ?>
<div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>No active Compulsory withdrawal policy is configured — the Annual Compulsory tab will be unavailable.</div>
<?php endif; ?>
<?php if (!$voluntaryPolicy): ?>
<div class="alert alert-warning small mb-3"><i class="bi bi-exclamation-triangle me-1"></i>No active Voluntary withdrawal policy is configured — the Voluntary tab will be unavailable.</div>
<?php endif; ?>

<form id="wdlForm" method="POST" action="<?=$formAction?>" novalidate>
<input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
<input type="hidden" name="withdrawal_type" id="withdrawalTypeHidden" value="<?= htmlspecialchars($initialType) ?>">
<input type="hidden" name="member_id" id="memberId" value="<?= (int)($withdrawal['member_id'] ?? $preselected['id'] ?? 0) ?>">

<div class="row g-4">
<div class="col-lg-8">

    <!-- Member Selection -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-person-circle text-danger"></i>
            <h6 class="mb-0 fw-semibold">Select Member</h6>
        </div>
        <div class="card-body p-4">
            <label class="form-label fw-semibold" for="memberSearch">
                Search Member <span class="text-danger">*</span>
            </label>
            <div class="input-group">
                <span class="input-group-text"><i class="bi bi-search"></i></span>
                <input type="text" id="memberSearch" class="form-control<?= $cls('member_id') ?>"
                       placeholder="Type name, number or phone…" autocomplete="off"
                       value="<?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name'].' ('.$preselected['member_number'].')') : '' ?>">
            </div>
            <?php if($err('member_id')): ?>
            <div class="text-danger small mt-1"><?= htmlspecialchars($err('member_id')) ?></div>
            <?php endif; ?>
            <div id="memberDropdown" class="list-group shadow mt-1"
                 style="position:absolute;z-index:1050;width:100%;max-width:480px;display:none;max-height:260px;overflow-y:auto;"></div>

            <div id="memberCard" class="d-none mt-3 p-3 rounded-3 bg-light border">
                <div class="row g-2 small" id="memberCardContent"></div>
                <div id="ineligibleAlert" class="d-none mt-2 alert alert-danger mb-0 py-2 small">
                    <i class="bi bi-exclamation-triangle-fill me-1"></i>
                    <span id="ineligibleText"></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Withdrawal Calculation -->
    <div class="card mb-4" id="savingsSummaryCard">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-calculator text-danger"></i>
            <h6 class="mb-0 fw-semibold" id="calcCardTitle">Compulsory Savings — Annual Withdrawal</h6>
            <span class="badge bg-danger-subtle text-danger ms-auto small">Server recalculates independently</span>
        </div>
        <div class="card-body p-4">
            <div class="row g-3 mb-3">
                <div class="col-sm-6">
                    <div class="detail-label" id="balanceLabel">Compulsory Savings Balance</div>
                    <div class="fw-bold fs-5 text-primary" id="displayBalance">Shs 0.00</div>
                </div>
                <div class="col-sm-6">
                    <div class="detail-label">Maximum Cash Withdrawal (<span id="maxPctLabel">—</span>%)</div>
                    <div class="fw-bold fs-5 text-success" id="displayMax">Shs 0.00</div>
                </div>
            </div>
            <hr>
            <div class="mb-3">
                <label class="form-label fw-semibold" for="requested_amount">
                    Requested Withdrawal Amount (Shs) <span class="text-danger">*</span>
                </label>
                <input type="number" id="requested_amount" name="requested_amount" step="0.01" min="0.01"
                       class="form-control form-control-lg<?= $cls('requested_amount') ?>"
                       value="<?= $v('requested_amount') ?>" placeholder="0.00" disabled required>
                <div class="form-text" id="maxHint"></div>
            </div>
            <div class="row g-3">
                <div class="col-sm-6" id="retainedCol">
                    <div class="p-3 bg-success bg-opacity-10 rounded-3 text-center">
                        <div class="text-success fw-semibold" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em">Converts to Share Capital</div>
                        <div class="fw-bold fs-4 text-success" id="displayRetained">Shs 0.00</div>
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="p-3 bg-primary bg-opacity-10 rounded-3 text-center">
                        <div class="text-primary fw-semibold" style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em" id="afterLabel">Remaining Compulsory Balance</div>
                        <div class="fw-bold fs-4 text-primary" id="displayAfter">Shs 0.00</div>
                    </div>
                </div>
            </div>
            <div class="alert alert-light border small mt-3 mb-0" id="modelAExplainer">
                <i class="bi bi-info-circle me-1"></i>
                Under the approved rule, the entire qualifying compulsory balance is
                consumed in this transaction: whatever is not paid out as cash converts
                fully to Share Capital, leaving a zero compulsory balance.
            </div>
        </div>
    </div>

    <!-- Payment Details -->
    <div class="card mb-4">
        <div class="card-header d-flex align-items-center gap-2">
            <i class="bi bi-cash-coin text-danger"></i>
            <h6 class="mb-0 fw-semibold">Payment Details</h6>
        </div>
        <div class="card-body p-4">
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="payment_method">Payment Method <span class="text-danger">*</span></label>
                    <select id="payment_method" name="payment_method" class="form-select" required>
                        <?php foreach($methods as $pm): ?>
                        <option value="<?=$pm?>" <?= ($v('payment_method','Cash')===$pm)?'selected':'' ?>><?=$pm?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold" for="withdrawal_date">Withdrawal Date <span class="text-danger">*</span></label>
                    <input type="date" id="withdrawal_date" name="withdrawal_date"
                           class="form-control" value="<?= $v('withdrawal_date', date('Y-m-d')) ?>"
                           max="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="reference_number">Reference Number</label>
                    <input type="text" id="reference_number" name="reference_number"
                           class="form-control" value="<?= $v('reference_number') ?>"
                           placeholder="Mobile Money code, cheque no…" maxlength="100">
                </div>
                <div class="col-12">
                    <label class="form-label fw-semibold" for="remarks">Remarks</label>
                    <textarea id="remarks" name="remarks" rows="2"
                              class="form-control" placeholder="Optional notes…"><?= $v('remarks') ?></textarea>
                </div>
            </div>
        </div>
    </div>

</div><!-- /.col-lg-8 -->

<!-- Summary Panel -->
<div class="col-lg-4">
    <div class="card mb-4 sticky-top" style="top:70px;border:1px solid rgba(220,38,38,.25);">
        <div class="card-header d-flex align-items-center gap-2" style="background:rgba(220,38,38,.08)">
            <i class="bi bi-receipt text-danger"></i>
            <h6 class="mb-0 fw-semibold text-danger">Withdrawal Summary</h6>
        </div>
        <div class="card-body p-4">
            <dl class="row mb-0 small">
                <dt class="col-7 text-muted">Withdrawal No.</dt>
                <dd class="col-5 fw-semibold text-danger"><?= htmlspecialchars($wdlNumber) ?></dd>
                <dt class="col-7 text-muted">Type</dt>
                <dd class="col-5 fw-semibold" id="sumType">Annual Compulsory</dd>
                <dt class="col-7 text-muted">Member</dt>
                <dd class="col-5" id="sumMember"><?= $preselected ? htmlspecialchars($preselected['first_name'].' '.$preselected['last_name']) : '—' ?></dd>
                <dt class="col-7 text-muted">Financial Year</dt>
                <dd class="col-5 fw-semibold"><?= $currentYear ?></dd>
                <hr class="my-2">
                <dt class="col-7 text-muted">Requested</dt>
                <dd class="col-5 fw-bold text-danger" id="sumRequested">—</dd>
                <dt class="col-7 text-muted" id="sumRetainedLabel">Share Capital</dt>
                <dd class="col-5 fw-bold text-success" id="sumRetained">—</dd>
            </dl>
        </div>
    </div>
    <div class="d-flex flex-column gap-2">
        <button type="submit" id="submitBtn" class="btn btn-danger w-100 fw-semibold py-2" disabled>
            <i class="bi bi-box-arrow-up-right me-2"></i>Process Withdrawal
        </button>
        <a href="<?=$base?>?page=withdrawals" class="btn btn-outline-secondary w-100">
            <i class="bi bi-x-lg me-1"></i>Cancel
        </a>
    </div>
</div>

</div><!-- /.row -->
</form>

<script>
(function(){
'use strict';
const fmt = n => parseFloat(n||0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g,',');
const setText = (id,v) => { const e=document.getElementById(id); if(e) e.textContent=v; };

let currentType = <?= json_encode($initialType) ?>;
let currentMember = null; // full member payload from AJAX search

const searchEl   = document.getElementById('memberSearch');
const dropdown   = document.getElementById('memberDropdown');
const memberCard = document.getElementById('memberCard');
const memberIdEl = document.getElementById('memberId');
const submitBtn  = document.getElementById('submitBtn');
const amountEl   = document.getElementById('requested_amount');
const typeHidden = document.getElementById('withdrawalTypeHidden');
let timer;

function updateTypeUI(){
    document.getElementById('tabCompulsory').classList.toggle('active', currentType === 'annual_compulsory');
    document.getElementById('tabVoluntary').classList.toggle('active', currentType === 'voluntary');
    typeHidden.value = currentType;

    const isAnnual = currentType === 'annual_compulsory';
    setText('calcCardTitle', isAnnual ? 'Compulsory Savings — Annual Withdrawal' : 'Voluntary Savings — Withdrawal');
    setText('balanceLabel', isAnnual ? 'Compulsory Savings Balance' : 'Voluntary Savings Balance');
    setText('afterLabel', isAnnual ? 'Remaining Compulsory Balance' : 'Remaining Voluntary Balance');
    setText('sumType', isAnnual ? 'Annual Compulsory' : 'Voluntary');
    setText('sumRetainedLabel', isAnnual ? 'Share Capital' : 'Share Capital (n/a)');
    document.getElementById('retainedCol').style.display = isAnnual ? '' : 'none';
    document.getElementById('modelAExplainer').style.display = isAnnual ? '' : 'none';

    if (currentMember) applyMemberForType();
}

document.getElementById('tabCompulsory').addEventListener('click', () => { currentType='annual_compulsory'; updateTypeUI(); });
document.getElementById('tabVoluntary').addEventListener('click', () => { currentType='voluntary'; updateTypeUI(); });

// Stage 13-F2 (13E-XSS-01 chain): m.full_name/m.member_number below come
// from a member search AJAX response -- ultimately the same member data a
// CSV import can write. escHtml() is the same safe-templating helper
// already used elsewhere in this codebase (app/views/loans/form.php) --
// it round-trips the string through a detached element's textContent so
// the returned string is HTML-safe to interpolate into a template
// literal, instead of splicing raw attacker-controlled text into markup.
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }

if(searchEl){
    searchEl.addEventListener('input', function(){
        clearTimeout(timer);
        if(this.value.trim().length<2){ if(dropdown) dropdown.style.display='none'; return; }
        timer = setTimeout(()=>{
            fetch('<?=APP_URL?>/index.php?page=withdrawal-member-search&q='+encodeURIComponent(this.value.trim()))
                .then(r=>r.json()).then(data=>{
                    dropdown.innerHTML='';
                    if(!data.members?.length){ dropdown.style.display='none'; return; }
                    data.members.forEach(m=>{
                        const a=document.createElement('a');
                        a.href='#'; a.className='list-group-item list-group-item-action py-2 px-3';
                        a.innerHTML=`<div class="fw-semibold small">${escHtml(m.full_name)}</div>
                                     <div class="text-muted" style="font-size:.75rem">${escHtml(m.member_number)} · Compulsory: Shs ${fmt(m.compulsory_balance)} · Voluntary: Shs ${fmt(m.voluntary_balance)}</div>`;
                        a.addEventListener('click',e=>{e.preventDefault();selectMember(m);});
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display='block';
                }).catch(()=>{});
        },300);
    });
    document.addEventListener('click',e=>{ if(!searchEl.contains(e.target)) dropdown.style.display='none'; });
}

function selectMember(m){
    memberIdEl.value = m.id;
    searchEl.value   = m.full_name + ' (' + m.member_number + ')';
    dropdown.style.display = 'none';
    currentMember = m;

    document.getElementById('memberCardContent').innerHTML =
        `<div class="col-sm-6"><span class="text-muted">Member</span><br><strong>${escHtml(m.full_name)}</strong></div>
         <div class="col-sm-6"><span class="text-muted">Member No.</span><br><strong>${escHtml(m.member_number)}</strong></div>
         <div class="col-sm-6"><span class="text-muted">Compulsory Balance</span><br><strong class="text-primary">Shs ${fmt(m.compulsory_balance)}</strong></div>
         <div class="col-sm-6"><span class="text-muted">Voluntary Balance</span><br><strong class="text-primary">Shs ${fmt(m.voluntary_balance)}</strong></div>
         <div class="col-sm-6"><span class="text-muted">Loan Outstanding</span><br><strong class="${m.loan_outstanding>0?'text-danger':'text-muted'}">Shs ${fmt(m.loan_outstanding)}</strong></div>`;
    memberCard.classList.remove('d-none');
    setText('sumMember', m.full_name);

    applyMemberForType();
}

function applyMemberForType(){
    const m = currentMember;
    if (!m) return;
    const isAnnual = currentType === 'annual_compulsory';
    const alert_ = document.getElementById('ineligibleAlert');
    const alertText = document.getElementById('ineligibleText');

    let ineligible = null;
    if (isAnnual) {
        if (!m.compulsory_account_id) ineligible = 'This member has no compulsory savings account.';
        else if (!m.compulsory_qualified) ineligible = 'This member\'s compulsory account has not met the qualification threshold.';
        else if (m.already_withdrew_annual) ineligible = 'This member has already made the annual withdrawal this financial year.';
    } else {
        if (!m.voluntary_account_id) ineligible = 'This member has no voluntary savings account.';
    }

    if (ineligible) {
        alertText.textContent = ineligible;
        alert_.classList.remove('d-none');
        submitBtn.disabled = true;
        amountEl.disabled = true;
        setDisplays(0, 0);
        return;
    }
    alert_.classList.add('d-none');
    amountEl.disabled = false;
    submitBtn.disabled = false;

    const balance = isAnnual ? m.compulsory_balance : m.voluntary_balance;
    const maxWithdrawal = isAnnual ? m.compulsory_max_withdrawal : m.voluntary_max_withdrawal;
    const pct = balance > 0 ? (maxWithdrawal / balance * 100) : 0;

    setText('displayBalance', 'Shs ' + fmt(balance));
    setText('displayMax', 'Shs ' + fmt(maxWithdrawal));
    setText('maxPctLabel', pct.toFixed(2));
    amountEl.max = maxWithdrawal.toFixed(2);
    document.getElementById('maxHint').textContent = 'Maximum collectible now: Shs ' + fmt(maxWithdrawal);

    calc();
}

function setDisplays(retained, after){
    setText('displayRetained', 'Shs ' + fmt(retained));
    setText('displayAfter', 'Shs ' + fmt(after));
    setText('sumRequested', 'Shs 0.00');
    setText('sumRetained', 'Shs ' + fmt(retained));
}

function calc(){
    if (!currentMember) return;
    const isAnnual = currentType === 'annual_compulsory';
    const balance = isAnnual ? currentMember.compulsory_balance : currentMember.voluntary_balance;
    const requested = Math.max(0, parseFloat(amountEl.value) || 0);

    let retained = 0, after = 0;
    if (isAnnual) {
        // Client-side preview only, mirrors Model A -- server always
        // recalculates authoritatively and never trusts this value.
        retained = Math.max(0, balance - requested);
        after = 0;
    } else {
        retained = 0;
        after = Math.max(0, balance - requested);
    }

    setText('displayRetained', 'Shs ' + fmt(retained));
    setText('displayAfter', 'Shs ' + fmt(after));
    setText('sumRequested', 'Shs ' + fmt(requested));
    setText('sumRetained', 'Shs ' + fmt(retained));
}
amountEl.addEventListener('input', calc);

// Form submit
const form = document.getElementById('wdlForm');
form.addEventListener('submit', function(e){
    const mid = parseInt(memberIdEl.value||0);
    if(!form.checkValidity() || mid<1){
        e.preventDefault(); e.stopPropagation();
        if(mid<1 && searchEl) searchEl.classList.add('is-invalid');
        const first=form.querySelector(':invalid');
        if(first) first.scrollIntoView({behavior:'smooth',block:'center'});
    } else {
        submitBtn.disabled=true;
        submitBtn.innerHTML='<span class="spinner-border spinner-border-sm me-2"></span>Processing…';
    }
    form.classList.add('was-validated');
});

updateTypeUI();
})();
</script>
