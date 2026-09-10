<?php
$pageTitle = $pageTitle ?? 'Other Income Categories';
$title = $pageTitle;
$icon  = 'bi-tags';
$canWrite = Session::hasRole(['admin', 'treasurer']);
?>

    <?php require VIEW_PATH . '/layouts/page-title.php'; ?>

    <?php if ($flash = Session::flash('success')): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="bi bi-check-circle me-2"></i><?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>
    <?php if ($flash = Session::flash('error')): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="bi bi-exclamation-triangle me-2"></i><?= htmlspecialchars($flash) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-tags me-2"></i>Other Income Categories</span>
            <?php if ($canWrite): ?>
                <button type="button" class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#createCategoryModal">
                    <i class="bi bi-plus-circle me-1"></i> New Category
                </button>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th class="ps-3">Name</th>
                            <th>Description</th>
                            <th>Income GL Account</th>
                            <th class="text-center">Status</th>
                            <?php if ($canWrite): ?>
                                <th class="text-end pe-3">Actions</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($categories)): ?>
                            <tr>
                                <td colspan="<?= $canWrite ? 5 : 4 ?>" class="text-center text-muted py-4">
                                    <i class="bi bi-tags fs-4 d-block mb-2 opacity-25"></i>
                                    No Other Income categories yet.
                                    <?php if ($canWrite): ?>
                                        <a href="#" data-bs-toggle="modal" data-bs-target="#createCategoryModal">Create one now.</a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($categories as $c): ?>
                                <tr class="<?= !$c['is_active'] ? 'text-muted' : '' ?>">
                                    <td class="ps-3 fw-semibold">
                                        <?= htmlspecialchars($c['category_name']) ?>
                                    </td>
                                    <td>
                                        <small class="text-muted"><?= htmlspecialchars($c['description'] ?? '') ?></small>
                                    </td>
                                    <td>
                                        <?php if ($c['gl_account_id']): ?>
                                            <span class="text-body">
                                                <?= htmlspecialchars($c['account_code'] . ' — ' . $c['account_name']) ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Not mapped</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php if ($c['is_active']): ?>
                                            <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                                        <?php endif; ?>
                                    </td>
                                    <?php if ($canWrite): ?>
                                        <td class="text-end pe-3">
                                            <button type="button"
                                                class="btn btn-sm btn-outline-secondary me-1 btn-edit-category"
                                                data-id="<?= $c['id'] ?>"
                                                data-name="<?= htmlspecialchars($c['category_name'], ENT_QUOTES) ?>"
                                                data-description="<?= htmlspecialchars($c['description'] ?? '', ENT_QUOTES) ?>"
                                                data-gl="<?= (int)$c['gl_account_id'] ?>"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editCategoryModal">
                                                <i class="bi bi-pencil"></i> Edit
                                            </button>
                                            <form method="POST"
                                                  action="<?= APP_URL ?>/index.php?page=other-income-category-toggle"
                                                  class="d-inline"
                                                  onsubmit="return confirm('<?= $c['is_active'] ? 'Deactivate' : 'Activate' ?> this category?')">
                                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                                                <input type="hidden" name="id" value="<?= $c['id'] ?>">
                                                <button type="submit"
                                                    class="btn btn-sm <?= $c['is_active'] ? 'btn-outline-danger' : 'btn-outline-success' ?>">
                                                    <i class="bi <?= $c['is_active'] ? 'bi-toggle-off' : 'bi-toggle-on' ?>"></i>
                                                    <?= $c['is_active'] ? 'Deactivate' : 'Activate' ?>
                                                </button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php if (!empty($categories)): ?>
            <div class="card-footer text-muted small">
                <?= count($categories) ?> categor<?= count($categories) === 1 ? 'y' : 'ies' ?>
                &nbsp;·&nbsp;
                <?= count(array_filter($categories, fn($c) => $c['gl_account_id'])) ?> mapped to GL
                &nbsp;·&nbsp;
                <?= count(array_filter($categories, fn($c) => !$c['gl_account_id'])) ?> unmapped
            </div>
        <?php endif; ?>
    </div>


<?php if ($canWrite): ?>
<!-- ════════════════════════════════════════════════════════════
     CREATE CATEGORY MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="createCategoryModal" tabindex="-1" aria-labelledby="createCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/index.php?page=other-income-category-store">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">

                <div class="modal-header">
                    <h5 class="modal-title" id="createCategoryModalLabel">
                        <i class="bi bi-plus-circle me-2"></i>New Other Income Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="create_category_name" class="form-label fw-semibold">
                            Category Name <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               id="create_category_name"
                               name="category_name"
                               class="form-control"
                               placeholder="e.g. Sale of Old Assets"
                               required
                               autofocus>
                    </div>

                    <div class="mb-3">
                        <label for="create_description" class="form-label fw-semibold">Description</label>
                        <textarea id="create_description"
                                  name="description"
                                  class="form-control"
                                  rows="2"
                                  placeholder="Brief description of what income belongs here"></textarea>
                    </div>

                    <div class="mb-1">
                        <label for="create_gl_account_id" class="form-label fw-semibold">
                            Income GL Account <span class="text-danger">*</span>
                        </label>
                        <select id="create_gl_account_id" name="gl_account_id" class="form-select" required>
                            <option value="" disabled selected>— Select an account —</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>">
                                    <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Required — only active income accounts are listed.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Create Category
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- ════════════════════════════════════════════════════════════
     EDIT CATEGORY MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal fade" id="editCategoryModal" tabindex="-1" aria-labelledby="editCategoryModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <form method="POST" action="<?= APP_URL ?>/index.php?page=other-income-category-update">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                <input type="hidden" name="id" id="edit_id">

                <div class="modal-header">
                    <h5 class="modal-title" id="editCategoryModalLabel">
                        <i class="bi bi-pencil me-2"></i>Edit Other Income Category
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label for="edit_category_name" class="form-label fw-semibold">
                            Category Name <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               id="edit_category_name"
                               name="category_name"
                               class="form-control"
                               required>
                    </div>

                    <div class="mb-3">
                        <label for="edit_description" class="form-label fw-semibold">Description</label>
                        <textarea id="edit_description"
                                  name="description"
                                  class="form-control"
                                  rows="2"></textarea>
                    </div>

                    <div class="mb-1">
                        <label for="edit_gl_account_id" class="form-label fw-semibold">
                            Income GL Account <span class="text-danger">*</span>
                        </label>
                        <select id="edit_gl_account_id" name="gl_account_id" class="form-select" required>
                            <option value="" disabled>— Select an account —</option>
                            <?php foreach ($accounts as $a): ?>
                                <option value="<?= $a['id'] ?>">
                                    <?= htmlspecialchars($a['code'] . ' — ' . $a['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            <i class="bi bi-info-circle me-1"></i>
                            Changing the GL mapping only affects future Other Income postings.
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle me-1"></i>Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<script>
document.querySelectorAll('.btn-edit-category').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('edit_id').value             = this.dataset.id;
        document.getElementById('edit_category_name').value  = this.dataset.name;
        document.getElementById('edit_description').value    = this.dataset.description;
        document.getElementById('edit_gl_account_id').value  = this.dataset.gl || '';
    });
});
</script>
<?php endif; ?>
