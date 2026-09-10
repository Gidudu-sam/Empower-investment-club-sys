<?php
$currentReport = $reportType ?? 'savings';
$tabs = [
    'weekly-savings'    => ['label' => 'Savings Deposits',    'icon' => 'bi-piggy-bank'],
    'weekly-loans'      => ['label' => 'Loan Disbursements',  'icon' => 'bi-bank2'],
    'weekly-repayments' => ['label' => 'Loan Repayments',     'icon' => 'bi-arrow-down-circle'],
    'weekly-overdue'    => ['label' => 'Overdue Loans',       'icon' => 'bi-exclamation-triangle'],
    'weekly-daily'      => ['label' => 'Daily Summary',       'icon' => 'bi-calendar-day'],
];
$activeMap = ['savings'=>'weekly-savings','loans'=>'weekly-loans','repayments'=>'weekly-repayments','overdue'=>'weekly-overdue','daily'=>'weekly-daily'];
$activePage = $activeMap[$currentReport] ?? '';
?>
<ul class="nav nav-pills flex-wrap gap-2 mb-4">
    <?php foreach ($tabs as $page => $tab): ?>
    <li class="nav-item">
        <a class="nav-link d-flex align-items-center gap-1 <?= $activePage === $page ? 'active' : '' ?>"
           href="<?= APP_URL ?>/index.php?page=<?= $page ?>">
            <i class="bi <?= $tab['icon'] ?>"></i>
            <span class="d-none d-md-inline"><?= $tab['label'] ?></span>
        </a>
    </li>
    <?php endforeach; ?>
</ul>
