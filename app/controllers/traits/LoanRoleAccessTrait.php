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
     *  (2026-09) -- covers approve/reject/disburse identically to Chairman,
     *  since Vice Chairman's mandate is full parity with Chairman across
     *  every workflow Chairman already approves. Secretary is deliberately
     *  NOT added here: this shared gate also governs LoanController's
     *  disburse() (real funds release), and management's decision scoped
     *  Secretary to loan APPLICATION approval only, not disbursement --
     *  see LoanApplicationController's own override of this method, which
     *  decouples application approval (a status/data decision, never a
     *  funds movement) from this trait's loan-approval-plus-disbursement
     *  bundle. */
    protected function requireApproverAccess(): void
    {
        Session::requireAuth();
        if (!Session::hasRole(['admin', 'chairman', 'vice_chairman'])) {
            Session::flash('error', 'Access denied. Only admin, chairman, or vice chairman can approve, reject, or disburse a loan.');
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
