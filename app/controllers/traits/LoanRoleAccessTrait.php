<?php
/**
 * LoanRoleAccessTrait — the single, shared definition of "who may
 * originate" and "who may approve" across the loan domain (Stage 9).
 *
 * Used by both LoanController (loans) and LoanApplicationController
 * (loan applications) so the two share one already-audited role check
 * rather than two independently-maintained copies that could drift apart.
 * This is what guarantees, by construction, that approving a loan
 * application and approving a loan itself are gated by the identical
 * role rule -- Chairman/Admin only, never System Admin or Loans Officer.
 */
trait LoanRoleAccessTrait
{
    /** Stage 1 security remediation: loan/application origination is
     *  financially significant; Admin and Loans Officer only. */
    protected function requireOriginateAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'loans_officer'])) {
            Session::flash('error', 'Access denied. Only admin or loans officer can record a new loan.');
            $this->redirect(APP_URL . '/index.php?page=' . $this->roleDeniedRedirectPage());
            exit;
        }
    }

    /** Stage 8: approval/rejection authority. Chairman or Admin only --
     *  System Admin never gains this by touching the loan domain.
     *
     *  Stage 23: Vice Chairman added as an explicit deputy/alternate
     *  approver alongside Chairman, per management's governance decision
     *  (2026-09) -- covers approve/reject identically to Chairman.
     *  Secretary is deliberately NOT added here: management's decision
     *  scoped Secretary to loan APPLICATION approval only, not loan
     *  approval itself -- see LoanApplicationController's own override
     *  of this method, which decouples application approval (a status/
     *  data decision) from this trait's loan-approval-plus-rejection gate.
     *  Disbursement is handled by requireDisburseAccess() below, which
     *  intentionally includes loans_officer as the operational role that
     *  physically releases the funds after the chairman approves. */
    protected function requireApproverAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
            Session::flash('error', 'Access denied. Only admin, chairman, or vice chairman can approve or reject a loan.');
            $this->redirect(APP_URL . '/index.php?page=' . $this->roleDeniedRedirectPage());
            exit;
        }
    }

    /** Disbursement authority — Treasurer and Cashier are the primary fund
     *  custodians who physically release funds to members after approval.
     *  Loans Officer coordinates the operational logistics. Admin and Chairman
     *  retain disbursement rights for operational flexibility and emergencies.
     *  
     *  Key roles:
     *  - Treasurer: Financial custodian, authorizes fund release
     *  - Cashier: Day-to-day cash handler, processes disbursements
     *  - Loans Officer: Operational coordinator (prepares, schedules, tracks)
     *  - Chairman: Full authority for emergencies/small club operations
     *  - Admin: System override capability
     *  
     *  Note: Vice Chairman can approve but NOT disburse (separation of duties).
     *  Note: Office Admin is explicitly excluded from disbursement authority. */
    protected function requireDisburseAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'chairman', 'treasurer', 'cashier', 'loans_officer'])) {
            Session::flash('error', 'Access denied. Only admin, chairman, treasurer, cashier, or loans officer can disburse a loan.');
            $this->redirect(APP_URL . '/index.php?page=' . $this->roleDeniedRedirectPage());
            exit;
        }
    }

    /** Where to bounce an unauthorized request back to. Override in the
     *  including controller if 'loans' isn't the right landing page. */
    protected function roleDeniedRedirectPage(): string
    {
        return 'loans';
    }
}
