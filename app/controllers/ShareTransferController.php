<?php

/**
 * ShareTransferController
 * 
 * Handles internal transfers between member Savings and Share accounts.
 */
class ShareTransferController
{
    private ShareTransferService $transferService;
    private MemberShareAccountModel $shareAccountModel;
    private MemberSavingsAccountModel $savingsAccountModel;
    private MemberModel $memberModel;

    public function __construct()
    {
        $this->transferService = new ShareTransferService();
        $this->shareAccountModel = new MemberShareAccountModel();
        $this->savingsAccountModel = new MemberSavingsAccountModel();
        $this->memberModel = new MemberModel();
    }

    /**
     * Show transfer form page
     */
    public function index(): void
    {
        Session::requireAuth();
        Session::requireRole(['admin', 'treasurer']);

        $pageTitle = 'Share Transfers';
        $activePage = 'shares';

        require_once __DIR__ . '/../views/layout/header.php';
        require_once __DIR__ . '/../views/shares/transfers.php';
        require_once __DIR__ . '/../views/layout/footer.php';
    }

    /**
     * Get member accounts for AJAX
     */
    public function getMemberAccounts(): void
    {
        Session::requireAuth();
        Session::requireRole(['admin', 'treasurer']);

        header('Content-Type: application/json');

        try {
            $memberId = (int)($_GET['member_id'] ?? 0);

            if ($memberId <= 0) {
                throw new RuntimeException("Invalid member ID");
            }

            // Get member details
            $member = $this->memberModel->findById($memberId);
            if (!$member) {
                throw new RuntimeException("Member not found");
            }

            // Get savings accounts
            $savingsAccounts = $this->savingsAccountModel->getMemberAccounts($memberId);
            
            // Get share account
            $shareAccount = $this->shareAccountModel->findByMemberId($memberId);

            // Calculate balances
            $savingsAccountsWithBalance = [];
            foreach ($savingsAccounts as $acc) {
                $balance = $this->savingsAccountModel->getAccountBalance((int)$acc['id']);
                $savingsAccountsWithBalance[] = [
                    'id' => $acc['id'],
                    'account_number' => $acc['account_number'],
                    'account_type' => $acc['account_type'],
                    'status' => $acc['status'],
                    'balance' => $balance
                ];
            }

            $shareAccountData = null;
            if ($shareAccount) {
                $shareAccountData = [
                    'id' => $shareAccount['id'],
                    'account_number' => $shareAccount['account_number'],
                    'status' => $shareAccount['status'],
                    'balance' => $this->shareAccountModel->getBalance((int)$shareAccount['id'])
                ];
            }

            echo json_encode([
                'success' => true,
                'member' => [
                    'id' => $member['id'],
                    'member_number' => $member['member_number'],
                    'name' => $member['first_name'] . ' ' . $member['last_name']
                ],
                'savings_accounts' => $savingsAccountsWithBalance,
                'share_account' => $shareAccountData
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process Savings → Shares transfer
     */
    public function savingsToShares(): void
    {
        Session::requireAuth();
        Session::requireRole(['admin', 'treasurer']);

        header('Content-Type: application/json');

        try {
            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !Session::validateCSRF($_POST['csrf_token'])) {
                throw new RuntimeException("Invalid security token");
            }

            // Get and validate inputs
            $memberId = (int)($_POST['member_id'] ?? 0);
            $savingsAccountId = (int)($_POST['savings_account_id'] ?? 0);
            $shareAccountId = (int)($_POST['share_account_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $narration = trim($_POST['narration'] ?? '');

            if ($memberId <= 0) {
                throw new RuntimeException("Invalid member");
            }

            if ($savingsAccountId <= 0) {
                throw new RuntimeException("Invalid savings account");
            }

            if ($shareAccountId <= 0) {
                throw new RuntimeException("Invalid share account");
            }

            if ($amount <= 0) {
                throw new RuntimeException("Amount must be greater than zero");
            }

            if (empty($narration)) {
                throw new RuntimeException("Narration is required");
            }

            // Execute transfer
            $result = $this->transferService->transferSavingsToShares(
                $memberId,
                $savingsAccountId,
                $shareAccountId,
                $amount,
                $narration,
                Session::getUserId()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Transfer completed successfully',
                'data' => $result
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Process Shares → Savings transfer
     */
    public function sharesToSavings(): void
    {
        Session::requireAuth();
        Session::requireRole(['admin', 'treasurer']);

        header('Content-Type: application/json');

        try {
            // Validate CSRF token
            if (!isset($_POST['csrf_token']) || !Session::validateCSRF($_POST['csrf_token'])) {
                throw new RuntimeException("Invalid security token");
            }

            // Get and validate inputs
            $memberId = (int)($_POST['member_id'] ?? 0);
            $shareAccountId = (int)($_POST['share_account_id'] ?? 0);
            $savingsAccountId = (int)($_POST['savings_account_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $narration = trim($_POST['narration'] ?? '');

            if ($memberId <= 0) {
                throw new RuntimeException("Invalid member");
            }

            if ($shareAccountId <= 0) {
                throw new RuntimeException("Invalid share account");
            }

            if ($savingsAccountId <= 0) {
                throw new RuntimeException("Invalid savings account");
            }

            if ($amount <= 0) {
                throw new RuntimeException("Amount must be greater than zero");
            }

            if (empty($narration)) {
                throw new RuntimeException("Narration is required");
            }

            // Execute transfer
            $result = $this->transferService->transferSharesToSavings(
                $memberId,
                $shareAccountId,
                $savingsAccountId,
                $amount,
                $narration,
                Session::getUserId()
            );

            echo json_encode([
                'success' => true,
                'message' => 'Transfer completed successfully',
                'data' => $result
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get transfer history
     */
    public function history(): void
    {
        Session::requireAuth();
        Session::requireRole(['admin', 'treasurer']);

        header('Content-Type: application/json');

        try {
            $memberId = isset($_GET['member_id']) ? (int)$_GET['member_id'] : null;
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
            $offset = isset($_GET['offset']) ? (int)$_GET['offset'] : 0;

            $db = Database::getInstance();
            
            $sql = "
                SELECT 
                    st.id,
                    st.member_id,
                    st.transaction_type,
                    st.transaction_date,
                    st.amount,
                    st.reference_number,
                    st.created_at,
                    m.member_number,
                    CONCAT(m.first_name, ' ', m.last_name) as member_name,
                    msa.account_number as share_account_number,
                    je.entry_number as journal_entry_number,
                    u.full_name as processed_by_name
                FROM share_transactions st
                INNER JOIN members m ON m.id = st.member_id
                INNER JOIN member_share_accounts msa ON msa.id = st.share_account_id
                LEFT JOIN journal_entries je ON je.id = st.journal_entry_id
                LEFT JOIN users u ON u.id = st.processed_by
                WHERE st.transaction_type IN ('transfer_in', 'transfer_out')
            ";

            $params = [];
            if ($memberId !== null) {
                $sql .= " AND st.member_id = ?";
                $params[] = $memberId;
            }

            $sql .= " ORDER BY st.transaction_date DESC, st.id DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;

            $transfers = $db->query($sql, $params)->fetchAll();

            echo json_encode([
                'success' => true,
                'transfers' => $transfers
            ]);

        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]);
        }
    }
}
