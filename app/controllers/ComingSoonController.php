<?php
require_once CORE_PATH . '/Controller.php';

/**
 * ComingSoonController
 * Handles all placeholder pages for modules not yet built.
 */
class ComingSoonController extends Controller
{
    /** Page metadata for each module */
    private const MODULES = [
        'contributions' => [
            'title'   => 'Contributions',
            'icon'    => 'bi-arrow-down-circle-fill',
            'color'   => 'success',
            'desc'    => 'Record and track member contributions and savings.',
        ],
        'loans' => [
            'title'   => 'Loans',
            'icon'    => 'bi-bank2',
            'color'   => 'warning',
            'desc'    => 'Manage loan applications, approvals, repayments and interest.',
        ],
        'expenses' => [
            'title'   => 'Expenses',
            'icon'    => 'bi-receipt',
            'color'   => 'danger',
            'desc'    => 'Record club expenses and operational costs.',
        ],
        'investments' => [
            'title'   => 'Investments',
            'icon'    => 'bi-graph-up-arrow',
            'color'   => 'info',
            'desc'    => 'Track investment portfolios, returns and dividends.',
        ],
        'reports' => [
            'title'   => 'Reports',
            'icon'    => 'bi-file-bar-graph-fill',
            'color'   => 'primary',
            'desc'    => 'Generate financial statements, member reports and activity summaries.',
        ],
        'settings' => [
            'title'   => 'Settings',
            'icon'    => 'bi-gear-fill',
            'color'   => 'secondary',
            'desc'    => 'Configure application settings, roles and permissions.',
        ],
        'audit' => [
            'title'   => 'Audit Log',
            'icon'    => 'bi-journal-text',
            'color'   => 'dark',
            'desc'    => 'View a complete audit trail of all system activity.',
        ],
    ];

    public function index(): void
    {
        Session::requireAuth();

        $page   = preg_replace('/[^a-z0-9\-_]/', '', $_GET['page'] ?? '');
        $module = self::MODULES[$page] ?? [
            'title' => ucfirst($page),
            'icon'  => 'bi-box',
            'color' => 'secondary',
            'desc'  => 'This module is under development.',
        ];

        $this->render('coming-soon', [
            'pageTitle'   => $module['title'] . ' — ' . APP_NAME,
            'breadcrumbs' => [['label' => $module['title']]],
            'module'      => $module,
            'page'        => $page,
        ], 'main');
    }
}
