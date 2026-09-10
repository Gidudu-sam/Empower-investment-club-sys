<?php $base = APP_URL . '/index.php'; ?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold">Bulk Member Account Provisioning</h1>
        <p class="text-muted mb-0 small">Upload a CSV of member numbers to create portal accounts in one batch</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=member-account-import-template" class="btn btn-outline-success btn-sm"><i class="bi bi-download me-1"></i>Download Template</a>
        <a href="<?= $base ?>?page=settings-users" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left me-1"></i>Back</a>
    </div>
</div>

<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i><div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i><div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<?php $result = Session::get('member_account_import_result'); if ($result): Session::remove('member_account_import_result'); ?>
<div class="card mb-4 border-success">
    <div class="card-header bg-success-subtle"><h6 class="mb-0 fw-semibold text-success"><i class="bi bi-check-circle-fill me-2"></i>Import Report</h6></div>
    <div class="card-body">
        <div class="row g-3 mb-3 text-center">
            <div class="col-3"><div class="fw-bold fs-4"><?= $result['total'] ?></div><div class="small text-muted">Total</div></div>
            <div class="col-3"><div class="fw-bold fs-4 text-success"><?= $result['created'] ?></div><div class="small text-muted">Created</div></div>
            <div class="col-3"><div class="fw-bold fs-4 text-warning"><?= $result['skipped'] ?></div><div class="small text-muted">Skipped</div></div>
            <div class="col-3"><div class="fw-bold fs-4 text-danger"><?= $result['errors'] ?></div><div class="small text-muted">Errors</div></div>
        </div>
        <?php if (!empty($result['credentials'])): ?>
        <div class="alert alert-warning small">
            <i class="bi bi-exclamation-triangle-fill me-1"></i>
            <strong>These temporary passwords are shown only once and are not stored anywhere in readable form.</strong>
            Record them now and communicate each one to the member directly. Every account must change this password at first login.
        </div>
        <div class="table-responsive mb-3">
            <table class="table table-sm table-bordered">
                <thead><tr><th>Member Number</th><th>Name</th><th>Login Email</th><th>Temporary Password</th></tr></thead>
                <tbody>
                    <?php foreach ($result['credentials'] as $c): ?>
                    <tr>
                        <td><?= htmlspecialchars($c['member_number']) ?></td>
                        <td><?= htmlspecialchars($c['full_name']) ?></td>
                        <td class="small"><?= htmlspecialchars($c['email']) ?></td>
                        <td class="font-monospace small"><?= htmlspecialchars($c['temp_password']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
        <?php if (!empty($result['log'])): ?>
        <details><summary class="small fw-semibold" style="cursor:pointer;">View full row-by-row log</summary>
            <div class="mt-2" style="max-height:200px;overflow-y:auto;">
                <?php foreach ($result['log'] as $e): ?>
                <div class="small py-1 border-bottom">
                    <span class="badge <?= $e['status']==='created' ? 'bg-success-subtle text-success' : ($e['status']==='skipped' ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger') ?>"><?= ucfirst($e['status']) ?></span>
                    Row <?= $e['row'] ?>: <?= htmlspecialchars($e['message']) ?>
                </div>
                <?php endforeach; ?>
            </div>
        </details>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card mb-4">
    <div class="card-header"><h6 class="mb-0 fw-semibold"><i class="bi bi-cloud-upload me-2"></i>Upload CSV</h6></div>
    <div class="card-body">
        <input type="file" id="importFile" accept=".csv" class="form-control mb-3">
        <button type="button" id="previewBtn" class="btn btn-primary btn-sm"><i class="bi bi-eye me-1"></i>Preview</button>
        <p class="text-muted small mt-2 mb-0">CSV must have one column: <code>member_number</code>. No account is created during preview.</p>
    </div>
</div>

<div class="card mb-4 d-none" id="previewCard">
    <div class="card-header"><h6 class="mb-0 fw-semibold">Preview</h6></div>
    <div class="card-body">
        <div class="row g-2 mb-3 text-center" id="previewTotals"></div>
        <div class="table-responsive" style="max-height:320px;overflow-y:auto;">
            <table class="table table-sm table-hover">
                <thead><tr><th>Row</th><th>Member Number</th><th>Name</th><th>Status</th></tr></thead>
                <tbody id="previewRows"></tbody>
            </table>
        </div>
        <form method="POST" action="<?= $base ?>?page=member-account-import-process" class="mt-3">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
            <input type="hidden" name="import_data" id="importDataField">
            <button type="submit" class="btn btn-success" id="confirmBtn" disabled
                    onclick="return confirm('Create these member accounts now? This cannot be undone.')">
                <i class="bi bi-check-circle me-1"></i>Confirm and Create Accounts
            </button>
        </form>
    </div>
</div>

<script>
document.getElementById('previewBtn').addEventListener('click', function () {
    const fileInput = document.getElementById('importFile');
    if (!fileInput.files.length) { alert('Choose a CSV file first.'); return; }
    const formData = new FormData();
    formData.append('import_file', fileInput.files[0]);
    fetch('<?= $base ?>?page=member-account-import-preview', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.error) { alert(data.error); return; }
            document.getElementById('previewCard').classList.remove('d-none');
            const t = data.totals;
            document.getElementById('previewTotals').innerHTML =
                '<div class="col"><div class="fw-bold">' + t.total + '</div><div class="small text-muted">Total</div></div>' +
                '<div class="col"><div class="fw-bold text-success">' + t.valid + '</div><div class="small text-muted">Valid</div></div>' +
                '<div class="col"><div class="fw-bold text-warning">' + t.already_has_account + '</div><div class="small text-muted">Already Have Account</div></div>' +
                '<div class="col"><div class="fw-bold text-danger">' + t.member_not_found + '</div><div class="small text-muted">Not Found</div></div>' +
                '<div class="col"><div class="fw-bold text-danger">' + t.duplicate_row + '</div><div class="small text-muted">Duplicate Rows</div></div>';
            const tbody = document.getElementById('previewRows');
            tbody.innerHTML = '';
            // Stage 13-F2 (13E-XSS-01 chain): this is the CSV import
            // preview itself -- row.member_number/full_name/message
            // contain uploaded-but-not-yet-saved data (message can even
            // echo back a raw member_number the operator typed into the
            // CSV, e.g. `No member with number "..." exists.`). Built
            // with textContent per cell, never innerHTML, so none of it
            // can execute as markup.
            data.rows.forEach(function (row) {
                const badge = row.status === 'valid' ? 'success' : (row.status === 'already_has_account' ? 'warning' : 'danger');
                const tr = document.createElement('tr');
                const tdNum = document.createElement('td'); tdNum.textContent = row.row_num; tr.appendChild(tdNum);
                const tdMno = document.createElement('td'); tdMno.textContent = row.member_number || ''; tr.appendChild(tdMno);
                const tdName = document.createElement('td'); tdName.textContent = row.full_name || '—'; tr.appendChild(tdName);
                const tdStatus = document.createElement('td');
                const statusBadge = document.createElement('span');
                statusBadge.className = 'badge bg-' + badge + '-subtle text-' + badge;
                statusBadge.textContent = row.status.replace(/_/g, ' ');
                const msgSpan = document.createElement('span');
                msgSpan.className = 'small text-muted';
                msgSpan.textContent = ' ' + (row.message || '');
                tdStatus.appendChild(statusBadge);
                tdStatus.appendChild(msgSpan);
                tr.appendChild(tdStatus);
                tbody.appendChild(tr);
            });
            document.getElementById('importDataField').value = JSON.stringify(data.rows);
            document.getElementById('confirmBtn').disabled = t.valid < 1;
        })
        .catch(() => alert('Preview failed. Please try again.'));
});
</script>
