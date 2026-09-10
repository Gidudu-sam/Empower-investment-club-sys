/**
 * Empower Investment Club — Main JavaScript
 */
'use strict';

document.addEventListener('DOMContentLoaded', function () {

    // ── Sidebar toggle ────────────────────────────────────────
    const toggleBtn = document.getElementById('sidebarToggle');
    if (toggleBtn) {
        toggleBtn.addEventListener('click', function (e) {
            e.preventDefault();
            document.body.classList.toggle('sb-sidenav-toggled');
            // Persist state in localStorage
            localStorage.setItem(
                'sidebarToggled',
                document.body.classList.contains('sb-sidenav-toggled')
            );
        });
    }

    // Restore sidebar state on page load (only on desktop)
    if (window.innerWidth > 991 && localStorage.getItem('sidebarToggled') === 'true') {
        document.body.classList.add('sb-sidenav-toggled');
    }

    // ── Auto-dismiss alerts after 5 seconds ──────────────────
    document.querySelectorAll('.alert.alert-dismissible').forEach(function (alert) {
        setTimeout(function () {
            const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
            if (bsAlert) bsAlert.close();
        }, 5000);
    });

    // ── Session timeout countdown ─────────────────────────────
    // Show a warning 2 minutes before session expires
    const SESSION_LIFETIME_MS = 3600 * 1000; // must match PHP SESSION_LIFETIME
    const WARNING_BEFORE_MS   = 2 * 60 * 1000;

    if (document.getElementById('layoutSidenav')) { // only on authenticated pages
        let warningShown = false;

        setTimeout(function () {
            if (!warningShown) {
                warningShown = true;
                showSessionWarning();
            }
        }, SESSION_LIFETIME_MS - WARNING_BEFORE_MS);
    }

    function showSessionWarning() {
        const toast = document.createElement('div');
        toast.className = 'toast align-items-center text-bg-warning border-0 position-fixed bottom-0 end-0 m-3';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.style.zIndex = '9999';
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="bi bi-clock-history me-2"></i>
                    Your session will expire in 2 minutes. Save your work.
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto"
                        data-bs-dismiss="toast" aria-label="Close"></button>
            </div>`;
        document.body.appendChild(toast);
        new bootstrap.Toast(toast, { delay: 10000 }).show();
    }

    // ── Confirm dangerous actions ─────────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!window.confirm(el.dataset.confirm || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

    // ── Active nav link highlight from URL ───────────────────
    const currentUrl = window.location.href;
    document.querySelectorAll('.sb-sidenav .nav-link').forEach(function (link) {
        if (link.href && currentUrl.includes(link.href) && link.href !== '#') {
            link.classList.add('active');
        }
    });

});
