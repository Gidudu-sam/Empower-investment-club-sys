<?php
$pageTitle = $pageTitle ?? 'Open Savings Account';
$title     = $pageTitle;
$icon      = 'bi-bank';
$base      = APP_URL . '/index.php';
?>

<style>
/* ── Card grid ───────────────────────────────────────────────────── */
.acct-type-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 24px;
    align-items: stretch;
    margin-bottom: 32px;
}
@media (max-width: 1100px) { .acct-type-grid { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 600px)  { .acct-type-grid { grid-template-columns: 1fr; } }

/* ── Base card ───────────────────────────────────────────────────── */
.acct-type-card {
    background: #fff;
    border: 1px solid #e5e7eb;
    border-top: 3px solid var(--acc);
    border-radius: 12px;
    padding: 1.85rem 1.6rem;
    display: flex;
    flex-direction: column;
    transition: box-shadow .15s ease, transform .15s ease;
}
.acct-type-card:hover { box-shadow: 0 4px 16px rgba(0,0,0,.08); transform: translateY(-1px); }

/* ── Icon container ──────────────────────────────────────────────── */
.acct-type-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    background: var(--acc-soft);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 18px;
    flex-shrink: 0;
}
.acct-type-icon i { color: var(--acc); font-size: 1.15rem; }

/* ── Title & description ─────────────────────────────────────────── */
.acct-type-title {
    font-size: 1.05rem;
    font-weight: 600;
    color: #111827;
    margin: 0 0 10px;
    line-height: 1.3;
}
.acct-type-desc {
    font-size: .84rem;
    color: #6b7280;
    line-height: 1.6;
    margin: 0;
    flex: 1;           /* pushes button to bottom */
}

/* ── Buttons ─────────────────────────────────────────────────────── */
.acct-type-btn {
    margin-top: 20px;
    display: block;
    width: 100%;
    padding: .55rem 1rem;
    border-radius: 8px;
    font-size: .875rem;
    font-weight: 600;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
    transition: background .15s, border-color .15s, opacity .15s;
    border: 1.5px solid var(--acc);
    background: var(--acc);
    color: #fff;
}
.acct-type-btn:hover { opacity: .88; color: #fff; text-decoration: none; }
</style>

<div class="pt-4 pb-4">
    <a href="<?= $base ?>?page=savings-accounts"
       style="font-size:.875rem;color:#6b7280;text-decoration:none;display:inline-flex;align-items:center;gap:6px;margin-bottom:22px;">
        <i class="bi bi-arrow-left"></i> Back to Savings Accounts
    </a>

    <?php $wrapperClass = 'mb-3'; require VIEW_PATH . '/layouts/page-title.php'; ?>

    <p style="font-size:.9rem;color:#6b7280;max-width:700px;line-height:1.6;margin-bottom:36px;">
        Choose the type of savings account to open. Each type serves a different purpose.
        Compulsory Savings is opened automatically when a member joins and isn't listed here.
    </p>

    <div class="acct-type-grid">

        <!-- ── Voluntary ───────────────────────────────────────────── -->
        <div class="acct-type-card" style="--acc:var(--brand-navy,#1B2B6B); --acc-soft:#eef0f9;">
            <div class="acct-type-icon"><i class="bi bi-person"></i></div>
            <p class="acct-type-title">Voluntary Savings</p>
            <p class="acct-type-desc">
                For individual members who want to save freely with no fixed contribution.
            </p>
            <a href="<?= $base ?>?page=savings-account-voluntary" class="acct-type-btn">
                Open account
            </a>
        </div>

        <!-- ── Fixed Deposit ──────────────────────────────────────── -->
        <div class="acct-type-card" style="--acc:#B8892B; --acc-soft:#f9f1e0;">
            <div class="acct-type-icon"><i class="bi bi-safe"></i></div>
            <p class="acct-type-title">Fixed Deposit</p>
            <p class="acct-type-desc">
                A single lump-sum deposit locked for a fixed term, earning simple interest at an agreed
                annual rate. No top-ups, no withdrawal before maturity.
            </p>
            <a href="<?= $base ?>?page=savings-account-fixed-deposit" class="acct-type-btn">
                Open account
            </a>
        </div>

        <!-- ── Joint ───────────────────────────────────────────────── -->
        <div class="acct-type-card" style="--acc:#2F6B4F; --acc-soft:#e6f0ea;">
            <div class="acct-type-icon"><i class="bi bi-people"></i></div>
            <p class="acct-type-title">Joint Savings</p>
            <p class="acct-type-desc">
                Shared account for two or more members with equal ownership.
            </p>
            <a href="<?= $base ?>?page=savings-account-joint" class="acct-type-btn">
                Open account
            </a>
        </div>

        <!-- ── Corporate ───────────────────────────────────────────── -->
        <div class="acct-type-card" style="--acc:#9C4221; --acc-soft:#f5e7e0;">
            <div class="acct-type-icon"><i class="bi bi-building"></i></div>
            <p class="acct-type-title">Corporate Savings</p>
            <p class="acct-type-desc">
                For organizations and companies, managed through appointed signatories.
            </p>
            <a href="<?= $base ?>?page=savings-account-corporate" class="acct-type-btn">
                Open account
            </a>
        </div>

    </div>
</div>
