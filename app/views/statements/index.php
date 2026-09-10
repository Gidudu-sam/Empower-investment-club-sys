<?php
$base = APP_URL . '/index.php';
$currentFY = StatementModel::dateToFY(new DateTime());
?>
<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-file-text-fill me-2" style="color:var(--brand-navy)"></i>Member Statements
        </h1>
        <p class="text-muted mb-0 small">Generate member savings, shares, and loan statements</p>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<div class="row justify-content-center">
    <div class="col-lg-7 col-md-9">
        <div class="card shadow-sm">
            <div class="card-header d-flex align-items-center gap-2" style="background:var(--brand-navy);color:#fff;border-radius:.5rem .5rem 0 0;">
                <i class="bi bi-file-earmark-person-fill"></i>
                <h6 class="mb-0 fw-semibold">Generate Statement</h6>
            </div>
            <div class="card-body p-4">

                <form id="stmtForm" method="GET" action="<?= $base ?>">
                    <input type="hidden" name="page" id="formPage" value="statement-view">

                    <!-- Member Search -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="memberSearch">
                            <i class="bi bi-person-circle me-1"></i>
                            Member <span class="text-danger">*</span>
                        </label>
                        <input type="hidden" name="member_id" id="memberId" value="0">
                        <div class="input-group">
                            <span class="input-group-text"><i class="bi bi-search"></i></span>
                            <input type="text" id="memberSearch" class="form-control form-control-lg"
                                   placeholder="Type name, member number or phone…"
                                   autocomplete="off">
                        </div>
                        <div id="memberDropdown" class="list-group shadow mt-1"
                             style="position:absolute;z-index:1050;width:100%;max-width:500px;display:none;max-height:260px;overflow-y:auto;"></div>

                        <!-- Selected member display -->
                        <div id="selectedMember" class="d-none mt-2 p-3 bg-light rounded-3 d-flex align-items-center gap-3">
                            <div class="member-avatar-sm bg-blue" id="memberInitial">?</div>
                            <div>
                                <div class="fw-semibold" id="memberName"></div>
                                <div class="text-muted small" id="memberInfo"></div>
                            </div>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="clearMember">
                                <i class="bi bi-x"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Statement Type -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="statementType">
                            <i class="bi bi-file-earmark-text me-1"></i>
                            Statement Type <span class="text-danger">*</span>
                        </label>
                        <select id="statementType" name="statement_type" class="form-select form-select-lg">
                            <option value="savings_account:compulsory">Compulsory Statement</option>
                            <option value="savings_account:joint">Joint Statement</option>
                            <option value="savings_account:fixed_deposit">Fixed Deposit Statement</option>
                            <option value="savings_account:voluntary">Voluntary Statement</option>
                            <option value="shares">Shares Statement</option>
                            <option value="loan">Loan Statement</option>
                        </select>
                    </div>

                    <!-- Period selector (for savings statement only -- shares is always a
                         point-in-time position as of a financial year, loan has its own flow) -->
                    <div class="mb-3" id="periodModeWrapper">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar-range me-1"></i>
                            Period
                        </label>
                        <div class="btn-group w-100 filter-pills" role="group">
                            <input type="radio" class="btn-check" name="range_mode" id="rangeModeFY" value="fy" checked>
                            <label class="btn" for="rangeModeFY">Financial Year</label>
                            <input type="radio" class="btn-check" name="range_mode" id="rangeModeCustom" value="custom">
                            <label class="btn" for="rangeModeCustom">Custom Range</label>
                        </div>
                    </div>

                    <!-- Financial Year (for savings/shares statement, FY mode) -->
                    <div class="mb-4" id="fyWrapper">
                        <label class="form-label fw-semibold" for="yearSelect">
                            <i class="bi bi-calendar3 me-1"></i>
                            Financial Year <span class="text-danger">*</span>
                        </label>
                        <select id="yearSelect" name="year" class="form-select form-select-lg">
                            <?php foreach ($years as $y): ?>
                            <option value="<?= $y ?>" <?= $y === $currentFY ? 'selected':'' ?>>
                                <?= $y ?>/<?= $y+1 ?> &nbsp;(<?= StatementModel::fyLabel($y) ?>)
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Financial year runs May to April. Current FY: <?= StatementModel::fyLabel($currentFY) ?>
                        </div>
                    </div>

                    <!-- Custom date range (for savings statement, custom mode) -->
                    <div class="mb-4 d-none" id="customRangeWrapper">
                        <label class="form-label fw-semibold">
                            <i class="bi bi-calendar-event me-1"></i>
                            Custom Date Range <span class="text-danger">*</span>
                        </label>
                        <div class="d-flex gap-2 align-items-center flex-wrap">
                            <input type="date" id="dateFrom" name="date_from" class="form-control form-control-lg" style="max-width:200px;">
                            <span class="text-muted">to</span>
                            <input type="date" id="dateTo" name="date_to" class="form-control form-control-lg" style="max-width:200px;">
                            <div class="btn-group ms-2" role="group">
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-days="7">1 Week</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-days="30">1 Month</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-days="90">3 Months</button>
                            </div>
                        </div>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Generate a statement for any specific period -- pick dates directly or use a quick range button.
                        </div>
                    </div>

                    <!-- Loan Select (for loan statement) -->
                    <div class="mb-4 d-none" id="loanSelectWrapper">
                        <label class="form-label fw-semibold" for="loanSelect">
                            <i class="bi bi-bank2 me-1"></i>
                            Select Loan <span class="text-danger">*</span>
                        </label>
                        <select id="loanSelect" name="id" class="form-select form-select-lg">
                            <option value="">-- Select member first --</option>
                        </select>
                        <div class="form-text" id="loanHelpText">
                            Choose a specific loan account to generate the statement.
                        </div>
                    </div>

                    <!-- Savings Account Select (for savings_account statement) -->
                    <div class="mb-4 d-none" id="savingsAccountSelectWrapper">
                        <label class="form-label fw-semibold" for="savingsAccountSelect">
                            <i class="bi bi-piggy-bank me-1"></i>
                            Select Savings Account <span class="text-danger">*</span>
                        </label>
                        <select id="savingsAccountSelect" name="id" class="form-select form-select-lg">
                            <option value="">-- Select member first --</option>
                        </select>
                        <div class="form-text" id="savingsAccountHelpText">
                            Choose the specific savings account (Compulsory, Voluntary, Joint, Corporate, or Fixed Monthly) to generate its own statement.
                        </div>
                    </div>

                    <div class="d-flex gap-3">
                        <button type="submit" id="generateBtn" class="btn btn-lg fw-semibold px-5" style="background:var(--brand-navy);color:#fff;" disabled>
                            <i class="bi bi-file-earmark-text me-2"></i>Generate Statement
                        </button>
                        <a href="<?= $base ?>?page=members" class="btn btn-lg btn-outline-secondary px-4">
                            <i class="bi bi-people me-1"></i>Members
                        </a>
                    </div>

                </form>
            </div>
        </div>

        <!-- Quick links to recent members -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-clock-history me-2 text-muted"></i>Quick Access
                </h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-2">Click a member to generate their statement directly:</p>
                <div id="quickLinks" class="d-flex flex-wrap gap-2">
                    <span class="text-muted small">Loading members…</span>
                </div>
            </div>
        </div>

        <?php if (!empty($canEmailAll)): ?>
        <!-- Bulk statement emailing -- restricted to admin/system_admin/
             office_admin, narrower than this page's general view-access
             gate (see StatementController::emailAll()'s docblock). -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="mb-0 fw-semibold">
                    <i class="bi bi-envelope-paper-fill me-2 text-muted"></i>Send Monthly Statements to All Members
                </h6>
            </div>
            <div class="card-body p-3">
                <p class="small text-muted mb-3">Emails every active member (with an email on file) their statement for the chosen period. Run this manually at least once a month.</p>
                <form method="POST" action="<?= $base ?>?page=statement-email-all" id="emailAllForm" onsubmit="return confirmEmailAll();">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="d-flex flex-wrap align-items-end gap-3">
                        <div>
                            <label class="form-label small text-muted mb-1" for="eaPeriod">Period</label>
                            <select id="eaPeriod" class="form-select form-select-sm" style="min-width:170px;">
                                <option value="fy">Financial Year</option>
                                <option value="1m" selected>Last 1 Month</option>
                                <option value="3m">Last 3 Months</option>
                                <option value="6m">Last 6 Months</option>
                                <option value="custom">Custom Range</option>
                            </select>
                        </div>
                        <div id="eaYearWrap" class="d-none">
                            <label class="form-label small text-muted mb-1" for="eaYear">Financial Year</label>
                            <select id="eaYear" class="form-select form-select-sm" style="min-width:180px;">
                                <?php foreach ($years as $y): ?>
                                <option value="<?= (int)$y ?>" <?= $y === $currentFY ? 'selected' : '' ?>><?= StatementModel::fyLabel($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div id="eaCustomWrap" class="d-none gap-2">
                            <div>
                                <label class="form-label small text-muted mb-1" for="eaFrom">From</label>
                                <input type="date" id="eaFrom" class="form-control form-control-sm">
                            </div>
                            <div>
                                <label class="form-label small text-muted mb-1" for="eaTo">To</label>
                                <input type="date" id="eaTo" class="form-control form-control-sm">
                            </div>
                        </div>
                        <div>
                            <label class="form-label small text-muted mb-1" for="eaType">Statement Type</label>
                            <select id="eaType" name="statement_type" class="form-select form-select-sm" style="min-width:140px;">
                                <option value="savings">Savings</option>
                                <option value="shares">Shares</option>
                            </select>
                        </div>
                        <input type="hidden" name="year" id="eaYearField">
                        <input type="hidden" name="range_mode" id="eaRangeModeField">
                        <input type="hidden" name="date_from" id="eaFromField">
                        <input type="hidden" name="date_to" id="eaToField">
                        <input type="hidden" name="force_resend" id="eaForceField" value="0">
                        <button type="submit" class="btn btn-sm btn-outline-primary">
                            <i class="bi bi-envelope-paper me-1"></i>Send to All Members
                        </button>
                    </div>
                    <div class="form-check mt-2">
                        <input type="checkbox" class="form-check-input" id="eaForceCheckbox">
                        <label class="form-check-label small text-muted" for="eaForceCheckbox">
                            Resend anyway, even if this exact period/type was already sent in the last 30 minutes
                        </label>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function(){
'use strict';
// Stage 13-F2: shared safe-templating helper (same pattern already used
// in app/views/loans/form.php) -- round-trips a string through a detached
// element's textContent so it is safe to interpolate into an HTML
// template literal built from untrusted member/loan/savings data.
function escHtml(str){ const d=document.createElement('div'); d.textContent=str||''; return d.innerHTML; }
const base = '<?= APP_URL ?>/index.php';
const memberIdEl  = document.getElementById('memberId');
const searchEl    = document.getElementById('memberSearch');
const dropdown    = document.getElementById('memberDropdown');
const selCard     = document.getElementById('selectedMember');
const generateBtn = document.getElementById('generateBtn');
const statementType = document.getElementById('statementType');
const fyWrapper   = document.getElementById('fyWrapper');
const loanWrapper = document.getElementById('loanSelectWrapper');
const loanSelect  = document.getElementById('loanSelect');
const savingsAccountWrapper = document.getElementById('savingsAccountSelectWrapper');
const savingsAccountSelect  = document.getElementById('savingsAccountSelect');
const formPage    = document.getElementById('formPage');
const periodModeWrapper = document.getElementById('periodModeWrapper');
const customRangeWrapper = document.getElementById('customRangeWrapper');
const rangeModeFY = document.getElementById('rangeModeFY');
const rangeModeCustom = document.getElementById('rangeModeCustom');
const dateFromEl = document.getElementById('dateFrom');
const dateToEl   = document.getElementById('dateTo');
let timer;

// The four named account-type options ("savings_account:compulsory" etc.)
// share the one existing account-level statement route/dropdown -- only the
// filter differs. memberSavingsAccounts caches the member's full, unfiltered
// account list from the last AJAX fetch so switching between these four
// options re-filters client-side instead of re-fetching.
const savingsAccountTypeLabels = { compulsory: 'Compulsory', joint: 'Joint', fixed_deposit: 'Fixed Deposit', voluntary: 'Voluntary' };
let memberSavingsAccounts = [];
function isSavingsAccountType(v){ return v.indexOf('savings_account:') === 0; }
function savingsAccountFilterKey(v){ return isSavingsAccountType(v) ? v.slice('savings_account:'.length) : null; }

function applyRangeMode(){
    if(statementType.value !== 'savings'){ return; } // custom ranges only make sense for the transaction-history statement
    if(rangeModeCustom.checked){
        fyWrapper.classList.add('d-none');
        customRangeWrapper.classList.remove('d-none');
    } else {
        fyWrapper.classList.remove('d-none');
        customRangeWrapper.classList.add('d-none');
    }
}
rangeModeFY.addEventListener('change', applyRangeMode);
rangeModeCustom.addEventListener('change', applyRangeMode);

document.querySelectorAll('[data-days]').forEach(btn => {
    btn.addEventListener('click', function(){
        const days = parseInt(this.dataset.days, 10);
        const to = new Date();
        const from = new Date();
        from.setDate(from.getDate() - days);
        dateToEl.value = to.toISOString().slice(0,10);
        dateFromEl.value = from.toISOString().slice(0,10);
        rangeModeCustom.checked = true;
        applyRangeMode();
    });
});

// loanSelect and savingsAccountSelect both use name="id" (each feeds a
// different target route's own id param) -- only one may ever be
// "live" at submit time, or the browser would send both and the server
// would only see whichever came last in the query string. A disabled
// form field is never submitted, so this is the actual mutual exclusion,
// not just the visual d-none toggling above it.
function setActiveIdField(which){
    loanSelect.disabled = (which !== 'loan');
    savingsAccountSelect.disabled = (which !== 'savings_account');
}

// Toggle statement type UI. Named (not anonymous) so it can also be run
// once on page load to sync the UI to whichever option is selected by
// default -- previously that was always "savings", now it's one of the
// four named account types, so the on-load sync is no longer a no-op.
function syncStatementTypeUI(){
    const value = statementType.value;
    if(value === 'loan'){
        fyWrapper.classList.add('d-none');
        periodModeWrapper.classList.add('d-none');
        customRangeWrapper.classList.add('d-none');
        loanWrapper.classList.remove('d-none');
        savingsAccountWrapper.classList.add('d-none');
        setActiveIdField('loan');
        formPage.value = 'loan-statement';
        // Validate if loan is selected
        if(parseInt(memberIdEl.value) > 0 && loanSelect.value){
            generateBtn.disabled = false;
        } else {
            generateBtn.disabled = true;
        }
    } else if(isSavingsAccountType(value)){
        // A specific savings account's own true statement -- delegates to
        // the existing account-level statement page (SavingsAccountController::
        // statement()), which handles its own period filtering internally,
        // so no Financial Year / Custom Range selector applies here.
        fyWrapper.classList.add('d-none');
        periodModeWrapper.classList.add('d-none');
        customRangeWrapper.classList.add('d-none');
        loanWrapper.classList.add('d-none');
        savingsAccountWrapper.classList.remove('d-none');
        setActiveIdField('savings_account');
        formPage.value = 'savings-account-statement';
        if(parseInt(memberIdEl.value) > 0){
            populateSavingsAccountSelect();
        } else {
            generateBtn.disabled = true;
        }
    } else if(value === 'shares'){
        // Shares are a point-in-time position, not a transaction ledger --
        // financial year only, no custom range.
        periodModeWrapper.classList.add('d-none');
        customRangeWrapper.classList.add('d-none');
        fyWrapper.classList.remove('d-none');
        loanWrapper.classList.add('d-none');
        savingsAccountWrapper.classList.add('d-none');
        setActiveIdField(null);
        formPage.value = 'statement-view';
        generateBtn.disabled = parseInt(memberIdEl.value) <= 0;
    } else {
        periodModeWrapper.classList.remove('d-none');
        loanWrapper.classList.add('d-none');
        savingsAccountWrapper.classList.add('d-none');
        setActiveIdField(null);
        formPage.value = 'statement-view';
        applyRangeMode();
        generateBtn.disabled = parseInt(memberIdEl.value) <= 0;
    }
}
statementType.addEventListener('change', syncStatementTypeUI);

// Member search
if(searchEl){
    searchEl.addEventListener('input',function(){
        clearTimeout(timer);
        if(this.value.trim().length<2){dropdown.style.display='none';return;}
        timer=setTimeout(()=>{
            fetch(base+'?page=statement-search&q='+encodeURIComponent(this.value.trim()))
                .then(r=>r.json()).then(data=>{
                    dropdown.innerHTML='';
                    if(!data.members?.length){dropdown.style.display='none';return;}
                    data.members.forEach(m=>{
                        const a=document.createElement('a');
                        a.href='#';a.className='list-group-item list-group-item-action py-2 px-3';
                        // Stage 13-F2 (13E-XSS-01 chain): m.full_name/
                        // m.member_number/m.phone are untrusted stored
                        // member data -- escHtml() (defined below) safely
                        // encodes them before they go into this template
                        // literal.
                        a.innerHTML=`<div class="fw-semibold small">${escHtml(m.full_name)}</div>
                                     <div class="text-muted" style="font-size:.75rem">${escHtml(m.member_number)} · ${escHtml(m.phone)}</div>`;
                        a.addEventListener('click',e=>{e.preventDefault();selectMember(m);});
                        dropdown.appendChild(a);
                    });
                    dropdown.style.display='block';
                }).catch(()=>{});
        },300);
    });
    document.addEventListener('click',e=>{if(!searchEl.contains(e.target)) dropdown.style.display='none';});
}

function selectMember(m){
    memberIdEl.value=m.id;
    searchEl.value=m.full_name+' ('+m.member_number+')';
    dropdown.style.display='none';
    document.getElementById('memberInitial').textContent=m.full_name.charAt(0).toUpperCase();
    document.getElementById('memberName').textContent=m.full_name;
    document.getElementById('memberInfo').textContent=m.member_number+' · '+m.phone;
    selCard.classList.remove('d-none');
    generateBtn.disabled=false;

    // Fetch member loans for loan statement dropdown. This must never touch
    // generateBtn when the operator is generating a savings/shares statement
    // (the bug: it used to disable the button unconditionally whenever the
    // selected member had no loans, even though "no loans" is completely
    // irrelevant outside the loan-statement flow -- so picking a member
    // with no loans looked like the button had silently stopped working).
    const loanHelpText = document.getElementById('loanHelpText');
    fetch(base+'?page=statement-member-loans&member_id='+m.id)
        .then(r=>r.json()).then(data=>{
            loanSelect.innerHTML='';
            if(!data.loans?.length){
                loanSelect.innerHTML='<option value="">-- No loans found for this member --</option>';
                if(loanHelpText){
                    // Stage 13-F2 (13E-XSS-01 chain): m.full_name is
                    // untrusted stored member data -- escaped before
                    // going into this innerHTML string.
                    loanHelpText.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>'
                        + escHtml(m.full_name) + ' has no loans on record, so a loan statement cannot be generated for them.';
                }
                if(statementType.value === 'loan'){ generateBtn.disabled = true; }
                return;
            }
            data.loans.forEach(l=>{
                const opt=document.createElement('option');
                opt.value=l.id;
                opt.textContent=l.loan_number + ' — Shs ' + Number(l.loan_amount).toLocaleString() + ' (' + l.status + ')';
                loanSelect.appendChild(opt);
            });
            if(loanHelpText){
                loanHelpText.textContent = 'Choose a specific loan account to generate the statement.';
            }
            if(statementType.value === 'loan'){
                generateBtn.disabled = false;
            }
        }).catch(()=>{
            loanSelect.innerHTML='<option value="">-- Error loading loans --</option>';
        });

    // Fetch this member's individual savings accounts for the Savings
    // Account Statement dropdown -- same fetch-and-populate pattern as
    // loans above. Every member has at least a Compulsory account (opened
    // automatically at registration), so an empty result here would be
    // unusual, but handled the same defensive way regardless. The full,
    // unfiltered list is cached in memberSavingsAccounts and rendered
    // through populateSavingsAccountSelect() so switching between the four
    // named account-type dropdown options re-filters without re-fetching.
    fetch(base+'?page=statement-member-savings-accounts&member_id='+m.id)
        .then(r=>r.json()).then(data=>{
            memberSavingsAccounts = data.accounts || [];
            populateSavingsAccountSelect();
        }).catch(()=>{
            memberSavingsAccounts = [];
            savingsAccountSelect.innerHTML='<option value="">-- Error loading savings accounts --</option>';
        });
}

// Renders savingsAccountSelect from the cached memberSavingsAccounts list,
// filtered to the currently-selected named account type (Compulsory/Joint/
// Fixed Deposit/Voluntary). Called after every fetch and whenever the
// statement type changes among those four options.
function populateSavingsAccountSelect(){
    const filterKey = savingsAccountFilterKey(statementType.value);
    const list = filterKey ? memberSavingsAccounts.filter(a => a.account_type_key === filterKey) : memberSavingsAccounts;
    const savingsAccountHelpText = document.getElementById('savingsAccountHelpText');
    savingsAccountSelect.innerHTML = '';
    if(!list.length){
        const label = filterKey ? (savingsAccountTypeLabels[filterKey] || '') : '';
        savingsAccountSelect.innerHTML = '<option value="">-- No ' + (label ? label + ' ' : '') + 'savings account found for this member --</option>';
        if(savingsAccountHelpText){
            // Stage 13-F2 (13E-XSS-01 chain): memberName is read back via
            // .textContent from a node that was itself populated with
            // untrusted member data (line ~441, `memberName.textContent =
            // m.full_name`). .textContent decodes any HTML-special
            // characters back to their literal form, so re-inserting the
            // raw string into innerHTML here would be a second-order
            // injection even though the original assignment was safe.
            // escHtml() re-encodes it before this concatenation.
            const memberName = escHtml(document.getElementById('memberName')?.textContent || 'This member');
            savingsAccountHelpText.innerHTML = '<i class="bi bi-exclamation-triangle-fill text-warning me-1"></i>'
                + memberName + ' has no ' + (label || 'matching') + ' savings account on record.';
        }
        if(isSavingsAccountType(statementType.value)){ generateBtn.disabled = true; }
        return;
    }
    list.forEach(a=>{
        const opt=document.createElement('option');
        opt.value=a.id;
        opt.textContent=a.account_type + ' — ' + a.account_number + ' — Shs ' + Number(a.balance).toLocaleString() + ' (' + a.status + ')';
        savingsAccountSelect.appendChild(opt);
    });
    if(savingsAccountHelpText){
        savingsAccountHelpText.textContent = 'Choose the specific ' + (savingsAccountTypeLabels[filterKey] || '') + ' account to generate its statement.';
    }
    if(isSavingsAccountType(statementType.value)){
        generateBtn.disabled = false;
    }
}

document.getElementById('clearMember')?.addEventListener('click',()=>{
    memberIdEl.value='0';
    searchEl.value='';
    selCard.classList.add('d-none');
    generateBtn.disabled=true;
    loanSelect.innerHTML='<option value="">-- Select member first --</option>';
    memberSavingsAccounts = [];
    savingsAccountSelect.innerHTML='<option value="">-- Select member first --</option>';
});

// Sync the UI to whichever statement type is selected by default on page
// load (see syncStatementTypeUI's comment above).
syncStatementTypeUI();

// Quick links
fetch(base+'?page=statement-search&q=EMP')
    .then(r=>r.json()).then(data=>{
        const ql=document.getElementById('quickLinks');
        if(!data.members?.length){ql.innerHTML='<span class="text-muted small">No members yet.</span>';return;}
        ql.innerHTML='';
        const fy=document.getElementById('yearSelect').value||'<?= $currentFY ?>';
        data.members.slice(0,12).forEach(m=>{
            const a=document.createElement('a');
            a.href=base+'?page=statement-view&member_id='+m.id+'&year='+fy;
            a.className='btn btn-sm btn-outline-primary';
            a.textContent=m.member_number;
            a.title=m.full_name;
            ql.appendChild(a);
        });
    }).catch(()=>{});

// Form validation
document.getElementById('stmtForm').addEventListener('submit',function(e){
    if(parseInt(memberIdEl.value)<1){
        e.preventDefault();
        searchEl.classList.add('is-invalid');
        searchEl.focus();
    } else if(statementType.value === 'loan' && !loanSelect.value){
        e.preventDefault();
        alert('Please select a loan account.');
    } else if(isSavingsAccountType(statementType.value) && !savingsAccountSelect.value){
        e.preventDefault();
        alert('Please select a savings account.');
    } else if(statementType.value === 'savings' && rangeModeCustom.checked){
        if(!dateFromEl.value || !dateToEl.value){
            e.preventDefault();
            alert('Please choose both a start and end date for the custom range.');
        } else if(dateFromEl.value > dateToEl.value){
            e.preventDefault();
            alert('The start date must be before the end date.');
        }
    }
});
})();
</script>

<?php if (!empty($canEmailAll)): ?>
<script>
(function () {
    var periodSelect = document.getElementById('eaPeriod');
    var yearWrap      = document.getElementById('eaYearWrap');
    var customWrap    = document.getElementById('eaCustomWrap');
    if (!periodSelect) { return; }

    function toggleWrap(el, show) {
        el.classList.toggle('d-none', !show);
        el.classList.toggle('d-flex', show);
    }
    function applyPeriodUI() {
        toggleWrap(yearWrap, periodSelect.value === 'fy');
        toggleWrap(customWrap, periodSelect.value === 'custom');
    }
    periodSelect.addEventListener('change', applyPeriodUI);
    applyPeriodUI();

    function fmtDate(d) {
        return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
    }

    window.confirmEmailAll = function () {
        var period = periodSelect.value;
        var type   = document.getElementById('eaType').value;

        if (period === 'fy') {
            document.getElementById('eaYearField').value = document.getElementById('eaYear').value;
        } else if (period === 'custom') {
            var from = document.getElementById('eaFrom').value;
            var to   = document.getElementById('eaTo').value;
            if (!from || !to) { alert('Please choose both a start and end date.'); return false; }
            if (from > to) { alert('The start date must be before the end date.'); return false; }
            document.getElementById('eaRangeModeField').value = 'custom';
            document.getElementById('eaFromField').value = from;
            document.getElementById('eaToField').value = to;
        } else {
            var to = new Date();
            var from = new Date();
            if (period === '7d') { from.setDate(from.getDate() - 7); }
            else { from.setMonth(from.getMonth() - { '1m': 1, '3m': 3, '6m': 6 }[period]); }
            document.getElementById('eaRangeModeField').value = 'custom';
            document.getElementById('eaFromField').value = fmtDate(from);
            document.getElementById('eaToField').value = fmtDate(to);
        }

        document.getElementById('eaForceField').value = document.getElementById('eaForceCheckbox').checked ? '1' : '0';

        return confirm('Send the ' + type + ' statement to every active member with an email on file? This cannot be undone.');
    };
})();
</script>
<?php endif; ?>
