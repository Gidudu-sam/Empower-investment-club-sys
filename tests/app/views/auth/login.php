<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --navy-950: #0B1229;
            --navy-900: #101A3D;
            --navy-800: #182552;
            --gold: #FF7E06;
            --gold-deep: #8F6A1E;
            --gold-soft: #F4E9D2;
            --paper: #F5F6FA;
            --ink: #171B2E;
            --slate: #6B7280;
            --slate-soft: #9AA1B4;
            --hairline: #E5E7F0;
        }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Inter', -apple-system, sans-serif;
            font-size: .875rem;
            -webkit-font-smoothing: antialiased;
        }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ── Left panel (dark brand) ─────────────────── */
        .login-left {
            width: 45%;
            background: var(--navy-950);
            padding: 3rem 3.5rem;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            overflow: hidden;
        }
        .login-left::before {
            content: '';
            position: absolute;
            top: -80px; right: -80px;
            width: 250px; height: 250px;
            background: radial-gradient(circle, rgba(184,137,43,.08) 0%, transparent 70%);
            pointer-events: none;
        }

        .brand-row {
            display: flex;
            align-items: center;
            gap: .75rem;
            margin-bottom: 3rem;
        }
        .brand-mark {
            width: 38px; height: 38px;
            border-radius: .45rem;
            background: linear-gradient(135deg, var(--gold), var(--gold-deep));
            display: flex; align-items: center; justify-content: center;
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700; font-size: 1rem; color: #fff;
            flex-shrink: 0;
        }
        .brand-text {
            line-height: 1.15;
        }
        .brand-text h1 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: .9rem; font-weight: 700; color: #fff; margin: 0;
        }
        .brand-text span {
            font-size: .55rem; font-weight: 500;
            color: rgba(255,255,255,.35);
            text-transform: uppercase; letter-spacing: .12em;
        }

        .login-left .eyebrow {
            font-size: .6rem;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--gold);
            margin-bottom: 1rem;
        }
        .login-left .headline {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.75rem;
            font-weight: 600;
            color: #fff;
            line-height: 1.25;
            margin-bottom: 1rem;
        }
        .login-left .subtext {
            font-size: .82rem;
            color: rgba(255,255,255,.45);
            line-height: 1.6;
            max-width: 340px;
        }

        /* Stats strip */
        .login-stats {
            display: flex;
            gap: 2.5rem;
            margin-top: auto;
            padding-top: 2rem;
            border-top: 1px solid rgba(255,255,255,.06);
        }
        .login-stat .figure {
            font-family: 'Space Grotesk', sans-serif;
            font-weight: 700;
            font-size: 1.3rem;
            color: #fff;
            font-variant-numeric: tabular-nums;
        }
        .login-stat .figure .curr {
            font-size: .65rem;
            font-weight: 400;
            color: rgba(255,255,255,.35);
        }
        .login-stat .label {
            font-size: .6rem;
            color: rgba(255,255,255,.35);
            margin-top: .15rem;
        }

        .login-footer {
            margin-top: 2rem;
            font-size: .65rem;
            color: rgba(255,255,255,.25);
        }

        /* ── Right panel (form) ──────────────────────── */
        .login-right {
            width: 55%;
            background: var(--paper);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem;
        }
        .login-form-box {
            width: 100%;
            max-width: 380px;
        }
        .login-form-box h2 {
            font-family: 'Space Grotesk', sans-serif;
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: .35rem;
        }
        .login-form-box .subtitle {
            font-size: .78rem;
            color: var(--slate);
            margin-bottom: 2rem;
        }

        .login-form-box .form-label {
            font-size: .72rem;
            font-weight: 600;
            color: var(--ink);
            margin-bottom: .35rem;
        }
        .login-form-box .form-control {
            font-size: .82rem;
            border: 1px solid var(--hairline);
            border-radius: .4rem;
            padding: .6rem .85rem;
            background: var(--surface, #fff);
        }
        .login-form-box .form-control:focus {
            border-color: var(--gold);
            box-shadow: 0 0 0 2px rgba(184,137,43,.08);
        }
        .login-form-box .input-group-text {
            border: 1px solid var(--hairline);
            background: #fff;
            color: var(--slate-soft);
            font-size: .82rem;
            border-radius: .4rem 0 0 .4rem;
        }
        .login-form-box .input-group .form-control {
            border-left: none;
        }
        .login-form-box .btn-toggle-pw {
            border: 1px solid var(--hairline);
            border-left: none;
            background: #fff;
            color: var(--slate-soft);
            border-radius: 0 .4rem .4rem 0;
            padding: .6rem .75rem;
        }
        .login-form-box .btn-toggle-pw:hover { color: var(--ink); }

        .login-form-box .form-check-input:checked {
            background-color: var(--gold);
            border-color: var(--gold);
        }

        .login-form-box .btn-signin {
            width: 100%;
            padding: .7rem;
            font-size: .82rem;
            font-weight: 600;
            border-radius: .4rem;
            background: var(--navy-950);
            border: none;
            color: #fff;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            transition: background .15s;
        }
        .login-form-box .btn-signin:hover { background: var(--navy-900); }
        .login-form-box .btn-signin:disabled { opacity: .6; }

        .forgot-link {
            font-size: .72rem;
            color: var(--slate);
            text-decoration: none;
            font-weight: 500;
        }
        .forgot-link:hover { color: var(--gold-deep); }

        /* Typewriter cursor */
        .typewriter::after {
            content: '|';
            animation: blink .7s infinite;
            color: var(--gold);
            font-weight: 300;
        }
        @keyframes blink {
            0%, 100% { opacity: 1; }
            50% { opacity: 0; }
        }

        /* Alerts */
        .login-alert {
            font-size: .78rem;
            padding: .6rem .85rem;
            border-radius: .35rem;
            margin-bottom: 1.25rem;
            display: flex;
            align-items: center;
            gap: .5rem;
        }
        .login-alert-danger { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .login-alert-warning { background: var(--gold-soft); border: 1px solid rgba(184,137,43,.2); color: var(--gold-deep); }

        /* Responsive */
        @media (max-width: 991.98px) {
            .login-wrapper { flex-direction: column; }
            .login-left { 
                width: 100%; 
                padding: 2rem 1.5rem 1.5rem;
                min-height: auto;
            }
            .login-right { width: 100%; padding: 2rem 1.5rem; }
            .login-left .headline { font-size: 1.35rem; }
            .login-stats { gap: 1.5rem; }
            
            /* Move footer inside form container on mobile */
            .login-footer-desktop { display: none; }
            .login-footer-mobile { 
                display: block;
                text-align: center;
                font-size: .65rem;
                color: var(--slate-soft);
                margin-top: 1.5rem;
                padding-top: 1.5rem;
                border-top: 1px solid var(--hairline);
            }
        }
        @media (max-width: 575.98px) {
            .login-left { 
                padding: 1.5rem 1.25rem 1.25rem;
            }
            .login-right { 
                padding: 1.5rem 1.25rem 1.25rem;
                padding-bottom: max(1.25rem, env(safe-area-inset-bottom));
            }
            .login-stats { flex-wrap: wrap; gap: 1rem; }
            
            /* Fix overlapping text - adjust spacing */
            .login-left .headline {
                font-size: 1.25rem;
                margin-bottom: 1.5rem !important;
            }
            
            /* Ensure form fits above fold */
            .login-form-box {
                max-width: 100%;
            }
            .login-form-box h2 {
                font-size: 1.25rem;
                margin-bottom: .25rem;
            }
            .login-form-box .subtitle {
                font-size: .75rem;
                margin-bottom: 1.5rem;
            }
            
            /* Improve form field contrast and touch targets */
            .login-form-box .form-label {
                font-size: .7rem;
                color: #374151; /* Darker for WCAG AAA */
            }
            .login-form-box .form-control {
                min-height: 44px; /* Touch target size */
                font-size: .85rem;
            }
            .login-form-box .input-group-text {
                min-width: 44px;
                justify-content: center;
            }
            .login-form-box .btn-toggle-pw {
                min-width: 44px;
                min-height: 44px;
            }
            
            /* Improve checkbox/link contrast */
            .form-check-label {
                color: #374151 !important; /* WCAG AAA compliant */
            }
            .forgot-link {
                color: var(--gold-deep) !important;
                font-weight: 600;
            }
            
            /* Ensure button is fully visible */
            .login-form-box .btn-signin {
                margin-bottom: 0;
                min-height: 44px;
            }
        }
    </style>
</head>
<body>

<div class="login-wrapper">

    <!-- ── LEFT: Brand panel ─────────────────────────────── -->
    <div class="login-left" style="justify-content:center;align-items:center;text-align:center;">
        <div>
            <!-- Brand -->
            <div class="brand-row" style="justify-content:center;gap:1.25rem;margin-bottom:2.5rem;">
                <img src="<?= APP_URL ?>/public/images/logo.png" alt="Empower Logo" style="width:110px;height:110px;border-radius:.85rem;object-fit:contain;">
                <div class="brand-text" style="text-align:left;">
                    <h1 style="font-size:2.4rem;margin-bottom:.2rem;">Empower</h1>
                    <span style="font-size:.85rem;letter-spacing:.16em;color:rgba(255,255,255,.5);">INVESTMENT CLUB</span>
                </div>
            </div>

            <!-- Slogan -->
            <div class="eyebrow" style="margin-bottom:.75rem;">Savings and Loans Management System</div>
            <div class="headline typewriter" id="typewriter" style="font-size:2rem;"></div>
        </div>

        <!-- Footer - desktop only -->
        <div class="login-footer-desktop" style="position:absolute;bottom:2rem;left:0;right:0;text-align:center;">
            <div class="login-footer">
                &copy; <?= date('Y') ?> Empower Investment Club &middot; Kampala, Uganda
            </div>
        </div>
    </div>

    <!-- ── RIGHT: Form panel ─────────────────────────────── -->
    <div class="login-right">
        <div class="login-form-box">

            <h2>Welcome back</h2>
            <p class="subtitle">Sign in with your administrator account</p>

            <!-- Session timeout -->
            <?php if (($reason ?? '') === 'timeout'): ?>
            <div class="login-alert login-alert-warning">
                <i class="bi bi-clock-history"></i>
                Your session expired. Please log in again.
            </div>
            <?php endif; ?>

            <!-- Account deactivated mid-session (Stage 13-C) -->
            <?php if (($reason ?? '') === 'deactivated'): ?>
            <div class="login-alert login-alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Your session is no longer valid. Please log in again, or contact your administrator if this is unexpected.
            </div>
            <?php endif; ?>

            <!-- Error -->
            <?php if (!empty($error)): ?>
            <div class="login-alert login-alert-danger">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>

            <!-- Form -->
            <form action="<?= APP_URL ?>/index.php?page=login" method="POST" novalidate id="loginForm">
                <input type="hidden" name="csrf_token"
                       value="<?= htmlspecialchars(Session::get('csrf_token', bin2hex(random_bytes(16)))) ?>">

                <!-- Email -->
                <div class="mb-3">
                    <label for="email" class="form-label">Email address</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-envelope"></i></span>
                        <input type="email" class="form-control" id="email" name="email"
                               placeholder="admin@empower.local"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               required autocomplete="email" autofocus>
                    </div>
                </div>

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <button class="btn-toggle-pw" type="button" id="togglePassword" aria-label="Show password">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember + Forgot -->
                <div class="d-flex align-items-center justify-content-between mb-4">
                    <div class="form-check">
                        <input type="checkbox" class="form-check-input" id="remember" name="remember">
                        <label class="form-check-label" for="remember" style="font-size:.72rem;color:var(--slate);">Keep me signed in</label>
                    </div>
                    <a href="#" class="forgot-link">Forgot password?</a>
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-signin" id="loginBtn">
                    <i class="bi bi-arrow-right-circle"></i>
                    Sign in
                </button>
            </form>

            <!-- Footer - mobile only -->
            <div class="login-footer-mobile" style="display:none;">
                &copy; <?= date('Y') ?> Empower Investment Club &middot; Kampala, Uganda
            </div>

        </div>
    </div>

</div>

<script>
(function () {
    'use strict';
    // Typewriter animation - Changed to "your" for user-centric tone
    const text = "Unleash your financial potential.";
    const el = document.getElementById('typewriter');
    let i = 0;
    function type() {
        if (i <= text.length) {
            el.textContent = text.slice(0, i);
            i++;
            setTimeout(type, 60);
        }
    }
    setTimeout(type, 500);

    // Form validation
    const form = document.getElementById('loginForm');
    form.addEventListener('submit', function (e) {
        if (!form.checkValidity()) {
            e.preventDefault();
            e.stopPropagation();
        } else {
            const btn = document.getElementById('loginBtn');
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Signing in…';
        }
        form.classList.add('was-validated');
    });
    document.getElementById('togglePassword').addEventListener('click', function () {
        const pwd  = document.getElementById('password');
        const icon = document.getElementById('toggleIcon');
        if (pwd.type === 'password') {
            pwd.type = 'text';
            icon.classList.replace('bi-eye', 'bi-eye-slash');
        } else {
            pwd.type = 'password';
            icon.classList.replace('bi-eye-slash', 'bi-eye');
        }
    });
})();
</script>
</body>
</html>
