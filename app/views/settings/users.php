<?php
$base = APP_URL . '/index.php';
$memberRoleId = 0;
foreach ($roles as $r) { if ($r['name'] === 'member') { $memberRoleId = (int)$r['id']; break; } }
?>

<div class="d-flex align-items-center justify-content-between mt-4 mb-3">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-person-gear me-2 text-primary"></i>User Management
        </h1>
        <p class="text-muted mb-0 small">Create, edit and manage system users</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= $base ?>?page=member-account-import" class="btn btn-outline-primary btn-sm">
            <i class="bi bi-people me-1"></i>Bulk Member Accounts
        </a>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#userModal" onclick="resetForm()">
            <i class="bi bi-person-plus me-1"></i>Add User
        </button>
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

<?php include VIEW_PATH . '/settings/partials/nav-tabs.php'; ?>

<!-- Users Table -->
<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <h6 class="mb-0 fw-semibold">System Users</h6>
        <span class="badge bg-primary-subtle text-primary"><?= count($users) ?> users</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">#</th>
                    <th>Name</th>
                    <th>Email</th>
                    <th>Phone</th>
                    <th>Role</th>
                    <th>Linked Member</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Last Login</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                <tr><td colspan="9" class="text-center py-4 text-muted">No users found.</td></tr>
                <?php else: foreach ($users as $i => $u): ?>
                <tr>
                    <td class="ps-3 text-muted small"><?= $i + 1 ?></td>
                    <td class="fw-semibold"><?= htmlspecialchars($u['full_name']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($u['email']) ?></td>
                    <td class="small text-muted"><?= htmlspecialchars($u['phone'] ?? '—') ?></td>
                    <td>
                        <span class="badge rounded-pill <?= match($u['role_name']){
                            'admin'         => 'bg-danger-subtle text-danger',
                            'treasurer'     => 'bg-warning-subtle text-warning',
                            'cashier'       => 'bg-info-subtle text-info',
                            'chairman'      => 'bg-primary-subtle text-primary',
                            'vice_chairman' => 'bg-primary-subtle text-primary',
                            'secretary'     => 'bg-success-subtle text-success',
                            default         => 'bg-secondary-subtle text-secondary'
                        } ?>"><?= htmlspecialchars($u['role_label']) ?></span>
                    </td>
                    <td class="small">
                        <?php if (!empty($u['member_id'])): ?>
                            <span class="text-muted"><?= htmlspecialchars($u['member_number'] ?? ('#' . $u['member_id'])) ?></span>
                            — <?= htmlspecialchars(trim(($u['member_first_name'] ?? '') . ' ' . ($u['member_last_name'] ?? ''))) ?>
                        <?php else: ?>
                            <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <span class="badge rounded-pill <?= $u['is_active'] ? 'bg-success-subtle text-success' : 'bg-secondary-subtle text-secondary' ?>">
                            <?= $u['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="text-center small text-muted">
                        <?= $u['last_login_at'] ? date('d M Y H:i', strtotime($u['last_login_at'])) : 'Never' ?>
                    </td>
                    <td class="text-center">
                        <div class="btn-group btn-group-sm">
                            <button class="btn btn-outline-primary" title="Edit"
                                    onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)">
                                <i class="bi bi-pencil"></i>
                            </button>
                            <?php if ((int)$u['id'] !== (int)Session::get('user_id')): ?>
                            <form method="POST" action="<?= $base ?>?page=settings-user-toggle" style="display:contents"
                                  onsubmit="return confirm('<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                <input type="hidden" name="id" value="<?= $u['id'] ?>">
                                <button type="submit" class="btn btn-outline-<?= $u['is_active'] ? 'danger' : 'success' ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>">
                                    <i class="bi bi-<?= $u['is_active'] ? 'x-circle' : 'check-circle' ?>"></i>
                                </button>
                            </form>
                            <button class="btn btn-outline-warning" title="Reset Password"
                                    onclick="resetPassword(<?= $u['id'] ?>, '<?= htmlspecialchars($u['full_name']) ?>')">
                                <i class="bi bi-key"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= $base ?>?page=settings-user-save">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="userId" value="0">
                <div class="modal-header">
                    <h5 class="modal-title" id="userModalTitle"><i class="bi bi-person-plus me-2"></i>Add User</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name *</label>
                        <input type="text" name="full_name" id="userFullName" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email *</label>
                        <input type="email" name="email" id="userEmail" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Phone</label>
                        <input type="text" name="phone" id="userPhone" class="form-control">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role *</label>
                        <select name="role_id" id="userRole" class="form-select" required>
                            <?php foreach ($roles as $r): ?>
                            <option value="<?= $r['id'] ?>"><?= htmlspecialchars($r['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text text-warning" id="ownRoleNote" style="display:none;">
                            <i class="bi bi-shield-lock me-1"></i>You cannot change your own role. Ask another administrator or system administrator.
                        </div>
                    </div>
                    <!-- Stage 14-B: shown only when Role = Member. The
                         administrator picks an EXISTING member here -- the
                         system resolves members.id, never a hand-typed
                         value. -->
                    <div class="mb-3" id="memberLinkGroup" style="display:none;">
                        <label class="form-label fw-semibold">Linked Member *</label>
                        <input type="hidden" name="member_id" id="userMemberId" value="">
                        <input type="text" id="memberSearchInput" class="form-control" autocomplete="off"
                               placeholder="Search by member number, name, or phone...">
                        <div id="memberSearchResults" class="list-group position-absolute shadow-sm" style="display:none;z-index:1060;max-height:220px;overflow-y:auto;width:calc(100% - 3rem);"></div>
                        <div class="form-text" id="memberLinkHint">Search and select the member this account belongs to.</div>
                        <div class="form-text text-warning" id="ownMemberLinkNote" style="display:none;">
                            <i class="bi bi-shield-lock me-1"></i>You cannot relink your own account to a different member. Ask another administrator or system administrator.
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password <span id="pwdHint" class="text-muted fw-normal">(required for new users)</span></label>
                        <div class="input-group">
                            <input type="password" name="password" id="userPassword" class="form-control" minlength="6">
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility(this, 'userPassword')" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary"><i class="bi bi-floppy me-1"></i>Save User</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Reset Password Modal -->
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form method="POST" action="<?= $base ?>?page=settings-user-reset">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="user_id" id="resetUserId" value="0">
                <div class="modal-header">
                    <h6 class="modal-title"><i class="bi bi-key me-2"></i>Reset Password</h6>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="small text-muted mb-3">Reset password for: <strong id="resetUserName"></strong></p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" id="resetNewPassword" class="form-control" minlength="6" required>
                            <button type="button" class="btn btn-outline-secondary" onclick="togglePasswordVisibility(this, 'resetNewPassword')" tabindex="-1">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-sm"><i class="bi bi-key me-1"></i>Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
const currentUserId = <?= (int)$currentUserId ?>;
const memberRoleId  = <?= (int)$memberRoleId ?>;
const base          = '<?= $base ?>';

function togglePasswordVisibility(btn, inputId) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('i');
    const showing = input.type === 'text';
    input.type = showing ? 'password' : 'text';
    icon.classList.toggle('bi-eye', showing);
    icon.classList.toggle('bi-eye-slash', !showing);
}

// Re-hides a password field and resets its eye icon -- called whenever a
// modal containing one is (re)opened, so a "shown" state from a previous
// open never carries over to a different user's password field.
function resetPasswordVisibility(inputId) {
    const input = document.getElementById(inputId);
    input.type = 'password';
    const btn = input.nextElementSibling;
    if (btn) {
        const icon = btn.querySelector('i');
        icon.classList.add('bi-eye');
        icon.classList.remove('bi-eye-slash');
    }
}

function toggleMemberLinkGroup() {
    const roleSelect = document.getElementById('userRole');
    const show = parseInt(roleSelect.value, 10) === memberRoleId;
    document.getElementById('memberLinkGroup').style.display = show ? '' : 'none';
    // Selecting a non-member role must never submit a stale member_id.
    if (!show) {
        document.getElementById('userMemberId').value = '';
        document.getElementById('memberSearchInput').value = '';
    }
}

function selectMemberForLink(m, disabled) {
    document.getElementById('userMemberId').value = m.id;
    document.getElementById('memberSearchInput').value = m.member_number + ' — ' + m.full_name;
    document.getElementById('memberSearchResults').style.display = 'none';
    const hint = document.getElementById('memberLinkHint');
    if (m.already_linked) {
        hint.innerHTML = '<span class="text-danger"><i class="bi bi-exclamation-triangle-fill me-1"></i>This member already has a portal account.</span>';
    } else {
        hint.textContent = 'Search and select the member this account belongs to.';
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.getElementById('userRole').addEventListener('change', toggleMemberLinkGroup);

    let searchTimer;
    const searchInput = document.getElementById('memberSearchInput');
    searchInput.addEventListener('input', function () {
        document.getElementById('userMemberId').value = '';
        clearTimeout(searchTimer);
        const q = this.value.trim();
        const results = document.getElementById('memberSearchResults');
        if (q.length < 2) { results.style.display = 'none'; return; }
        searchTimer = setTimeout(function () {
            fetch(base + '?page=settings-user-member-search&q=' + encodeURIComponent(q))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    results.innerHTML = '';
                    if (!data.members || !data.members.length) { results.style.display = 'none'; return; }
                    data.members.forEach(function (m) {
                        const item = document.createElement('a');
                        item.href = '#';
                        item.className = 'list-group-item list-group-item-action py-2';
                        item.style.fontSize = '.82rem';
                        // Stage 13-F2 (13E-XSS-01 chain): m.member_number/
                        // m.full_name/m.phone come from a member search
                        // AJAX response -- untrusted stored member data.
                        // Built with DOM nodes + textContent instead of
                        // an innerHTML string so it can never be
                        // interpreted as markup.
                        const nameDiv = document.createElement('div');
                        nameDiv.className = 'fw-semibold';
                        nameDiv.textContent = m.member_number + ' — ' + m.full_name;
                        const phoneDiv = document.createElement('div');
                        phoneDiv.className = 'text-muted';
                        phoneDiv.style.fontSize = '.72rem';
                        phoneDiv.textContent = m.phone || '';
                        if (m.already_linked) {
                            const linkedSpan = document.createElement('span');
                            linkedSpan.className = 'text-danger';
                            linkedSpan.textContent = ' · already has an account';
                            phoneDiv.appendChild(linkedSpan);
                        }
                        item.appendChild(nameDiv);
                        item.appendChild(phoneDiv);
                        item.addEventListener('click', function (e) { e.preventDefault(); selectMemberForLink(m); });
                        results.appendChild(item);
                    });
                    results.style.display = '';
                })
                .catch(function () { results.style.display = 'none'; });
        }, 250);
    });
    document.addEventListener('click', function (e) {
        const results = document.getElementById('memberSearchResults');
        if (!searchInput.contains(e.target) && !results.contains(e.target)) results.style.display = 'none';
    });
});

function resetForm() {
    document.getElementById('userId').value = '0';
    document.getElementById('userFullName').value = '';
    document.getElementById('userEmail').value = '';
    document.getElementById('userPhone').value = '';
    document.getElementById('userRole').value = '3';
    document.getElementById('userRole').onchange = null;
    document.getElementById('ownRoleNote').style.display = 'none';
    document.getElementById('userMemberId').value = '';
    document.getElementById('memberSearchInput').value = '';
    document.getElementById('memberSearchInput').disabled = false;
    document.getElementById('ownMemberLinkNote').style.display = 'none';
    document.getElementById('userPassword').value = '';
    document.getElementById('userPassword').required = true;
    resetPasswordVisibility('userPassword');
    document.getElementById('userModalTitle').innerHTML = '<i class="bi bi-person-plus me-2"></i>Add User';
    document.getElementById('pwdHint').textContent = '(required for new users)';
    toggleMemberLinkGroup();
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function editUser(user) {
    document.getElementById('userId').value = user.id;
    document.getElementById('userFullName').value = user.full_name;
    document.getElementById('userEmail').value = user.email;
    document.getElementById('userPhone').value = user.phone || '';
    const roleSelect = document.getElementById('userRole');
    roleSelect.value = user.role_id;
    // UX signal only, not the security mechanism -- the backend
    // (SettingsController::userSave()) independently rejects a self-role
    // change regardless of what this control allows client-side. Deliberately
    // NOT using the `disabled` attribute: a disabled field is excluded from
    // form submission entirely, which would send no role_id at all and trip
    // the backend's own self-role-change guard on every self-edit (even one
    // that only changes, say, a phone number) -- so instead the select stays
    // enabled and submits its real value, and this just snaps any attempted
    // change back so the visible state matches what will actually happen.
    const isSelf = parseInt(user.id, 10) === currentUserId;
    document.getElementById('ownRoleNote').style.display = isSelf ? '' : 'none';
    roleSelect.onchange = isSelf ? function () { roleSelect.value = user.role_id; toggleMemberLinkGroup(); } : toggleMemberLinkGroup;

    // Pre-fill the linked-member field for an existing member account.
    // Same non-disabled-field reasoning as the role note above: a self
    // relink is still blocked server-side regardless of this UI hint.
    toggleMemberLinkGroup();
    document.getElementById('userMemberId').value = user.member_id || '';
    document.getElementById('memberSearchInput').value = user.member_id
        ? ((user.member_number || ('#' + user.member_id)) + ' — ' + ((user.member_first_name || '') + ' ' + (user.member_last_name || '')).trim())
        : '';
    document.getElementById('ownMemberLinkNote').style.display = (isSelf && parseInt(user.role_id, 10) === memberRoleId) ? '' : 'none';
    document.getElementById('memberSearchInput').disabled = isSelf;

    document.getElementById('userPassword').value = '';
    document.getElementById('userPassword').required = false;
    resetPasswordVisibility('userPassword');
    document.getElementById('userModalTitle').innerHTML = '<i class="bi bi-pencil me-2"></i>Edit User';
    document.getElementById('pwdHint').textContent = '(leave blank to keep current)';
    new bootstrap.Modal(document.getElementById('userModal')).show();
}

function resetPassword(id, name) {
    document.getElementById('resetUserId').value = id;
    document.getElementById('resetUserName').textContent = name;
    document.getElementById('resetNewPassword').value = '';
    resetPasswordVisibility('resetNewPassword');
    new bootstrap.Modal(document.getElementById('resetModal')).show();
}
</script>
