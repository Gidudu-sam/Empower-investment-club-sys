<?php
/* Members — List View */
$baseUrl = APP_URL . '/index.php';
// Matches MemberController::requireAddAccess() / requireEditAccess() / requireAdmin() exactly.
$canAddMember  = Session::hasRole(['admin', 'office_admin']);
$canEditMember = Session::hasRole(['admin', 'office_admin']); // MemberController::requireEditAccess exact -- treasurer is view-only
$canAdminMember = Session::hasRole(['admin']);
?>

<!-- Page Title Row -->
<div class="d-flex align-items-center justify-content-between mt-4 mb-3 flex-wrap gap-2">
    <div>
        <h1 class="h3 mb-1 fw-bold text-gray-800">
            <i class="bi bi-people me-2 text-primary"></i>Members
        </h1>
        <p class="text-muted mb-0 small">
            <?= number_format($countAll) ?> total &nbsp;·&nbsp;
            <?= number_format($countActive) ?> active &nbsp;·&nbsp;
            <?= number_format($countDormant) ?> dormant &nbsp;·&nbsp;
            <?= number_format($countInactive) ?> inactive
        </p>
    </div>
    <?php if ($canAddMember): ?>
    <a href="<?= $baseUrl ?>?page=member-add" class="btn btn-primary">
        <i class="bi bi-person-plus-fill me-1"></i> Add Member
    </a>
    <?php endif; ?>
</div>

<!-- Alerts -->
<?php if (!empty($success)): ?>
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($success) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>
<?php if (!empty($error)): ?>
<div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
    <div><?= htmlspecialchars($error) ?></div>
    <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stat strips -->
<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-card-primary">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-primary"><i class="bi bi-people-fill"></i></div>
                <div>
                    <div class="stat-label">Total Members</div>
                    <div class="stat-value"><?= number_format($countAll) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-card-success">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-success"><i class="bi bi-person-check-fill"></i></div>
                <div>
                    <div class="stat-label">Active</div>
                    <div class="stat-value"><?= number_format($countActive) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-card-accent">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-accent"><i class="bi bi-moon-stars-fill"></i></div>
                <div>
                    <div class="stat-label">Dormant</div>
                    <div class="stat-value"><?= number_format($countDormant) ?></div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="stat-card stat-card-warning">
            <div class="d-flex align-items-center gap-3">
                <div class="stat-icon-box stat-icon-warning"><i class="bi bi-person-dash-fill"></i></div>
                <div>
                    <div class="stat-label">Inactive</div>
                    <div class="stat-value"><?= number_format($countInactive) ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Search / Filter card -->
<div class="card mb-0">

    <!-- Filter bar -->
    <div class="card-header">
        <form id="filterForm" method="GET" action="<?= $baseUrl ?>" class="row g-2 align-items-end">
            <input type="hidden" name="page" value="members">

            <div class="col-lg-4 col-md-6">
                <label class="form-label form-label-sm mb-1">Search</label>
                <div class="input-group input-group-sm">
                    <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
                    <input type="text" name="search" id="searchInput"
                           class="form-control"
                           placeholder="Name, number, phone, email, ID…"
                           value="<?= htmlspecialchars($search) ?>">
                </div>
            </div>

            <div class="col-lg-2 col-md-3 col-sm-4">
                <label class="form-label form-label-sm mb-1">Status</label>
                <select name="status" class="form-select form-select-sm" id="statusFilter">
                    <option value="">All</option>
                    <option value="active"   <?= $status === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="dormant"  <?= $status === 'dormant'  ? 'selected' : '' ?>>Dormant</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>

            <div class="col-lg-2 col-md-3 col-sm-4">
                <label class="form-label form-label-sm mb-1">From Date</label>
                <input type="date" name="date_from" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateFrom) ?>">
            </div>

            <div class="col-lg-2 col-md-3 col-sm-4">
                <label class="form-label form-label-sm mb-1">To Date</label>
                <input type="date" name="date_to" class="form-control form-control-sm"
                       value="<?= htmlspecialchars($dateTo) ?>">
            </div>

            <div class="col-auto d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-funnel me-1"></i>Filter
                </button>
                <?php if ($search || $status || $dateFrom || $dateTo): ?>
                <a href="<?= $baseUrl ?>?page=members" class="btn btn-outline-secondary btn-sm">
                    <i class="bi bi-x-lg me-1"></i>Clear
                </a>
                <?php endif; ?>
            </div>
        </form>
    </div>

    <!-- Table -->
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="membersTable">
            <thead>
                <tr>
                    <th class="ps-3" style="width:105px">Member No.</th>
                    <th style="width:52px">Photo</th>
                    <th>Full Name</th>
                    <th class="d-none d-md-table-cell">Phone</th>
                    <th class="d-none d-xl-table-cell">Email</th>
                    <th class="text-center" style="width:90px">Status</th>
                    <th class="d-none d-lg-table-cell" style="width:110px">Join Date</th>
                    <th class="text-end pe-3" style="width:115px">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($members)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-people fs-1 d-block mb-2 opacity-25"></i>
                        <?php if ($search || $status || $dateFrom || $dateTo): ?>
                            No members match your filters.
                            <a href="<?= $baseUrl ?>?page=members" class="d-block mt-1">Clear filters</a>
                        <?php else: ?>
                            No members yet.
                            <?php if ($canAddMember): ?>
                            <a href="<?= $baseUrl ?>?page=member-add" class="d-block mt-1">Add the first member</a>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($members as $m): ?>
                <?php
                    $fullName  = htmlspecialchars($m['first_name'] . ' ' . $m['last_name']);
                    $initial   = strtoupper(substr($m['first_name'], 0, 1));
                    $isFemale  = $m['gender'] === 'Female';
                    $photoUrl  = !empty($m['passport_photo'])
                                    ? APP_URL . '/public/uploads/members/' . htmlspecialchars($m['passport_photo'])
                                    : null;
                ?>
                <tr>
                    <td class="ps-3">
                        <a href="<?= $baseUrl ?>?page=member-view&id=<?= $m['id'] ?>"
                           class="fw-semibold text-primary text-decoration-none small">
                            <?= htmlspecialchars($m['member_number']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($photoUrl): ?>
                        <img src="<?= $photoUrl ?>" alt="<?= $fullName ?>"
                             class="rounded-circle" width="36" height="36"
                             style="object-fit:cover;">
                        <?php else: ?>
                        <div class="member-avatar-sm <?= $isFemale ? 'bg-pink' : 'bg-blue' ?>">
                            <?= $initial ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold"><?= $fullName ?></div>
                        <div class="text-muted small d-md-none"><?= htmlspecialchars($m['phone']) ?></div>
                    </td>
                    <td class="d-none d-md-table-cell"><?= htmlspecialchars($m['phone']) ?></td>
                    <td class="d-none d-xl-table-cell">
                        <?= $m['email'] ? htmlspecialchars($m['email']) : '<span class="text-muted fst-italic">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <?php if ($m['status'] === 'active'): ?>
                            <span class="badge bg-success-subtle text-success rounded-pill px-2 py-1">Active</span>
                        <?php elseif ($m['status'] === 'dormant'): ?>
                            <span class="badge rounded-pill px-2 py-1" style="background:rgba(253,126,20,.12);color:#fd7e14;">Dormant</span>
                        <?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary rounded-pill px-2 py-1">Inactive</span>
                        <?php endif; ?>
                    </td>
                    <td class="d-none d-lg-table-cell">
                        <?= $m['join_date'] ? date('d M Y', strtotime($m['join_date'])) : '—' ?>
                    </td>
                    <td class="text-end pe-3">
                        <div class="d-flex justify-content-end gap-1">
                            <a href="<?= $baseUrl ?>?page=member-view&id=<?= $m['id'] ?>"
                               class="btn btn-sm btn-outline-primary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            <?php if ($canEditMember): ?>
                            <a href="<?= $baseUrl ?>?page=member-edit&id=<?= $m['id'] ?>"
                               class="btn btn-sm btn-outline-secondary" title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php endif; ?>
                            <?php if ($canAdminMember): ?>
                            <button type="button"
                                    class="btn btn-sm btn-outline-danger js-delete"
                                    data-id="<?= $m['id'] ?>"
                                    data-name="<?= $fullName ?>"
                                    data-number="<?= htmlspecialchars($m['member_number']) ?>"
                                    title="Delete">
                                <i class="bi bi-trash"></i>
                            </button>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="card-footer d-flex align-items-center justify-content-between flex-wrap gap-2">
        <div class="text-muted small">
            Page <?= $currentPage ?> of <?= $pages ?> &nbsp;·&nbsp; <?= number_format($total) ?> records
        </div>
        <nav>
            <ul class="pagination pagination-sm mb-0">
                <li class="page-item <?= $currentPage <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="<?= $baseUrl ?>?page=members&p=<?= $currentPage-1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">
                        <i class="bi bi-chevron-left"></i>
                    </a>
                </li>
                <?php
                $lo = max(1, $currentPage - 2);
                $hi = min($pages, $currentPage + 2);
                for ($pg = $lo; $pg <= $hi; $pg++):
                ?>
                <li class="page-item <?= $pg === $currentPage ? 'active' : '' ?>">
                    <a class="page-link"
                       href="<?= $baseUrl ?>?page=members&p=<?= $pg ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">
                        <?= $pg ?>
                    </a>
                </li>
                <?php endfor; ?>
                <li class="page-item <?= $currentPage >= $pages ? 'disabled' : '' ?>">
                    <a class="page-link"
                       href="<?= $baseUrl ?>?page=members&p=<?= $currentPage+1 ?>&search=<?= urlencode($search) ?>&status=<?= urlencode($status) ?>&date_from=<?= urlencode($dateFrom) ?>&date_to=<?= urlencode($dateTo) ?>">
                        <i class="bi bi-chevron-right"></i>
                    </a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>

</div><!-- /.card -->

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header bg-danger text-white border-0">
                <h5 class="modal-title">
                    <i class="bi bi-exclamation-triangle-fill me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body py-4">
                <p class="mb-1 text-muted small">You are about to permanently delete:</p>
                <p class="fw-bold mb-0" id="deleteInfo">—</p>
                <p class="text-danger small mt-2 mb-0">
                    <i class="bi bi-info-circle me-1"></i>This action cannot be undone.
                </p>
            </div>
            <div class="modal-footer border-0 pt-0">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="<?= APP_URL ?>/index.php?page=member-delete" class="d-inline">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <input type="hidden" name="id" id="confirmDeleteId" value="">
                    <button type="submit" id="confirmDeleteBtn" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Member
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
/* v2.0 - Manual search only */
(function () {
    'use strict';

    // Delete modal wiring
    document.querySelectorAll('.js-delete').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id  = btn.dataset.id;
            const num = btn.dataset.number;
            const nm  = btn.dataset.name;
            document.getElementById('deleteInfo').textContent = num + ' — ' + nm;
            document.getElementById('confirmDeleteId').value = id;
            new bootstrap.Modal(document.getElementById('deleteModal')).show();
        });
    });

    // Manual search only - user presses Enter or clicks Filter button
    const si = document.getElementById('searchInput');
    const form = document.getElementById('filterForm');
    
    // Submit on Enter key in search box
    if (si) {
        si.addEventListener('keypress', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                form.submit();
            }
        });
    }
})();
</script>
