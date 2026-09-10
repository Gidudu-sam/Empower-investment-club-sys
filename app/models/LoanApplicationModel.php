<?php
/**
 * LoanApplicationModel — Loan Applications (Stage 9)
 *
 * A loan application is a distinct record from a loan: it captures a
 * member's requested terms, goes through its own draft -> pending_approval
 * -> approved/rejected workflow (mirroring LoanModel's own proven
 * workflow), and only an approved application may ever be converted into
 * a real loan (see LoanController::convertApplication()/handleSave()).
 * The `converted_loan_id` UNIQUE constraint on this table is the hard,
 * database-level guarantee that one application converts to at most one
 * loan -- not merely a UI convention.
 */
class LoanApplicationModel extends Model
{
    protected string $table      = 'loan_applications';
    protected string $primaryKey = 'id';

    public function generateApplicationNumber(): string
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT MAX(CAST(SUBSTRING(`application_number`, 5) AS UNSIGNED)) AS max_seq FROM `loan_applications`"
            );
            $stmt->execute();
            $row  = $stmt->fetch();
            $next = (int)($row['max_seq'] ?? 0) + 1;
            return 'APP-' . str_pad($next, 6, '0', STR_PAD_LEFT);
        } catch (PDOException $e) {
            return 'APP-000001';
        }
    }

    public function findWithDetails(int $id): array|false
    {
        $stmt = $this->db->prepare(
            "SELECT a.*, m.first_name, m.last_name, m.member_number, lt.name AS loan_type_name,
                    l.loan_number AS converted_loan_number
             FROM `loan_applications` a
             JOIN `members` m ON m.id = a.member_id
             JOIN `loan_types` lt ON lt.id = a.loan_type_id
             LEFT JOIN `loans` l ON l.id = a.converted_loan_id
             WHERE a.id = ? LIMIT 1"
        );
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    public function getAll(): array
    {
        return $this->db->query(
            "SELECT a.*, m.first_name, m.last_name, m.member_number, lt.name AS loan_type_name
             FROM `loan_applications` a
             JOIN `members` m ON m.id = a.member_id
             JOIN `loan_types` lt ON lt.id = a.loan_type_id
             ORDER BY a.created_at DESC"
        )->fetchAll();
    }

    /** Stage 23: mirrors LoanModel::pendingApproval() exactly -- feeds the
     *  dashboard's Pending Approvals widget for the roles authorized to
     *  approve/reject a loan application (see LoanApplicationController's
     *  overridden requireApproverAccess()). */
    public function pendingApproval(): array
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT a.*, m.first_name, m.last_name, m.member_number,
                        u.full_name AS recorded_by_name
                 FROM `loan_applications` a
                 JOIN `members` m ON m.id = a.member_id
                 LEFT JOIN `users` u ON u.id = a.recorded_by
                 WHERE a.status = 'pending_approval'
                 ORDER BY a.submitted_at ASC"
            );
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            return [];
        }
    }

    /** Applications an authorized user can pick from Record Loan --
     *  approved and not yet converted to a loan. */
    public function getConvertibleApplications(): array
    {
        return $this->db->query(
            "SELECT a.*, m.first_name, m.last_name, m.member_number, lt.name AS loan_type_name
             FROM `loan_applications` a
             JOIN `members` m ON m.id = a.member_id
             JOIN `loan_types` lt ON lt.id = a.loan_type_id
             WHERE a.status = 'approved' AND a.converted_loan_id IS NULL
             ORDER BY a.approved_at DESC"
        )->fetchAll();
    }

    // ================================================================
    // APPROVAL WORKFLOW (mirrors LoanModel::submit()/approve()/reject())
    // ================================================================

    public function submit(int $id, int $userId): void
    {
        $app = $this->find($id);
        if (!$app) {
            throw new InvalidArgumentException("Loan application id {$id} does not exist.");
        }
        if (!in_array($app['status'], ['draft', 'rejected'], true)) {
            throw new InvalidArgumentException("Only a draft or rejected application can be submitted for approval (current status: {$app['status']}).");
        }

        $this->db->prepare(
            "UPDATE `loan_applications` SET status = 'pending_approval', submitted_at = NOW(),
             rejected_by = NULL, rejected_at = NULL, rejection_reason = NULL WHERE id = ?"
        )->execute([$id]);
    }

    /**
     * Approve an application. Unlike a plain loan approval, this may set
     * an approved amount/duration different from what was requested --
     * that distinction (requested vs. approved) is the whole point of
     * separating "application" from "loan".
     */
    public function approve(int $id, int $userId, float $approvedAmount, int $approvedPeriodMonths): void
    {
        $app = $this->find($id);
        if (!$app) {
            throw new InvalidArgumentException("Loan application id {$id} does not exist.");
        }
        if ($app['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval application can be approved (current status: {$app['status']}).");
        }
        if ((int)$app['recorded_by'] === $userId) {
            throw new InvalidArgumentException('You cannot approve an application you created yourself. Ask another approver to review it.');
        }
        if ($approvedAmount <= 0) {
            throw new InvalidArgumentException('Approved amount must be greater than zero.');
        }
        if ($approvedPeriodMonths <= 0) {
            throw new InvalidArgumentException('Approved period must be greater than zero.');
        }

        $this->db->prepare(
            "UPDATE `loan_applications`
             SET status = 'approved', approved_by = ?, approved_at = NOW(),
                 approved_amount = ?, approved_period_months = ?
             WHERE id = ?"
        )->execute([$userId, $approvedAmount, $approvedPeriodMonths, $id]);
    }

    public function reject(int $id, int $userId, string $reason): void
    {
        if (trim($reason) === '') {
            throw new InvalidArgumentException('A rejection reason is required.');
        }
        $app = $this->find($id);
        if (!$app) {
            throw new InvalidArgumentException("Loan application id {$id} does not exist.");
        }
        if ($app['status'] !== 'pending_approval') {
            throw new InvalidArgumentException("Only a pending-approval application can be rejected (current status: {$app['status']}).");
        }

        $this->db->prepare(
            "UPDATE `loan_applications` SET status = 'rejected', rejected_by = ?, rejected_at = NOW(), rejection_reason = ? WHERE id = ?"
        )->execute([$userId, $reason, $id]);
    }

    /**
     * Server-side re-fetch + validation for conversion into a loan. Never
     * trust a hidden form field for the application's own content --
     * always re-read this fresh. Throws with a clear message if the
     * application cannot be converted right now; callers must not attempt
     * to silently work around any of these conditions.
     */
    public function getConvertibleOrFail(int $id): array
    {
        $app = $this->find($id);
        if (!$app) {
            throw new InvalidArgumentException("Loan application id {$id} does not exist.");
        }
        if ($app['status'] !== 'approved') {
            throw new InvalidArgumentException("Loan application {$app['application_number']} is not approved (current status: {$app['status']}) and cannot be recorded as a loan.");
        }
        if (!empty($app['converted_loan_id'])) {
            throw new InvalidArgumentException("Loan application {$app['application_number']} has already been converted to a loan.");
        }
        return $app;
    }

    /**
     * Mark an application as converted. Called inside the same DB
     * transaction as the new loan's creation. The UNIQUE key on
     * converted_loan_id is the hard backstop -- even if two concurrent
     * requests both pass getConvertibleOrFail()'s check, only one can
     * successfully attach itself here.
     */
    public function markConverted(int $id, int $loanId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE `loan_applications` SET converted_loan_id = ?, converted_at = NOW() WHERE id = ? AND converted_loan_id IS NULL"
        );
        $stmt->execute([$loanId, $id]);
        if ($stmt->rowCount() === 0) {
            // Someone else converted this application in the moment between
            // getConvertibleOrFail() and here -- the caller's transaction
            // must roll back the loan it just created rather than leave two
            // loans pointing at (or claiming) the same application.
            throw new InvalidArgumentException('This application was converted to a loan by another request just now. Please refresh and check whether the loan already exists before trying again.');
        }
    }
}
