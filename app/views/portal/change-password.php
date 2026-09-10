<div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
        <div class="card">
            <div class="card-header"><h5 class="mb-0 fw-semibold"><i class="bi bi-key me-2"></i>Change Your Password</h5></div>
            <div class="card-body">
                <p class="text-muted small">For your security, you must set a new password before continuing to use your account.</p>
                <?php if (!empty($error)): ?>
                <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle-fill me-1"></i><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST" action="<?= APP_URL ?>/index.php?page=portal-change-password">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Current Password</label>
                        <div class="input-group">
                            <input type="password" name="current_password" class="form-control pwd-field" required>
                            <button type="button" class="btn btn-outline-secondary pwd-toggle" tabindex="-1" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password</label>
                        <div class="input-group">
                            <input type="password" name="new_password" class="form-control pwd-field" minlength="6" required>
                            <button type="button" class="btn btn-outline-secondary pwd-toggle" tabindex="-1" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Confirm New Password</label>
                        <div class="input-group">
                            <input type="password" name="confirm_password" class="form-control pwd-field" minlength="6" required>
                            <button type="button" class="btn btn-outline-secondary pwd-toggle" tabindex="-1" aria-label="Show password">
                                <i class="bi bi-eye"></i>
                            </button>
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-check-circle me-1"></i>Change Password</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.querySelectorAll('.pwd-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var input = this.previousElementSibling;
        var icon  = this.querySelector('i');
        var showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.classList.toggle('bi-eye', showing);
        icon.classList.toggle('bi-eye-slash', !showing);
        this.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
});
</script>
