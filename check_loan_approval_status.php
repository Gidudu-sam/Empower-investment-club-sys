<?php
/**
 * Check Approval Status for 10M Loan
 * Shows who has approved and who's left to approve
 */

require_once __DIR__ . '/core/Database.php';
require_once __DIR__ . '/app/config/database.php';

$db = Database::getInstance()->getConnection();

echo "\n" . str_repeat("=", 80) . "\n";
echo "LOAN APPROVAL STATUS CHECK - 10M Loan\n";
echo str_repeat("=", 80) . "\n\n";

// Find the 10M loan
$stmt = $db->prepare("
    SELECT id, loan_number, member_id, loan_amount, status, 
           submitted_at, approved_by, approved_at, recorded_by
    FROM loans 
    WHERE loan_amount = 10000000
    ORDER BY id DESC
    LIMIT 1
");
$stmt->execute();
$loan = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$loan) {
    echo "❌ No 10M loan found in the database.\n\n";
    exit;
}

echo "LOAN DETAILS:\n";
echo "  Loan Number: {$loan['loan_number']}\n";
echo "  Amount: UGX " . number_format($loan['loan_amount'], 2) . "\n";
echo "  Status: {$loan['status']}\n";
echo "  Submitted: " . ($loan['submitted_at'] ?? 'Not submitted') . "\n";
echo "  Approved By (legacy): " . ($loan['approved_by'] ?? 'None') . "\n";
echo "  Approved At (legacy): " . ($loan['approved_at'] ?? 'None') . "\n\n";

// Check if there's an approval round
$stmt = $db->prepare("
    SELECT ar.*, 
           ap.transaction_type,
           at.tier_number, at.tier_name
    FROM transaction_approval_rounds ar
    LEFT JOIN approval_policies ap ON ar.policy_id = ap.id
    LEFT JOIN approval_tiers at ON ar.tier_id = at.id
    WHERE ar.transaction_type = 'loan' 
      AND ar.transaction_id = ?
    ORDER BY ar.id DESC
    LIMIT 1
");
$stmt->execute([$loan['id']]);
$round = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$round) {
    echo "ℹ️  No multi-approval round found.\n";
    echo "   This loan may be using the legacy single-approval system.\n";
    echo "   Status: {$loan['status']}\n\n";
    
    if ($loan['status'] === 'pending_approval') {
        echo "   The loan is pending approval but no approval tracking record exists.\n";
        echo "   Someone with approval authority (Chairman/Vice Chairman/Admin) needs to approve it.\n\n";
    }
    exit;
}

echo "APPROVAL ROUND:\n";
echo "  Round ID: {$round['id']}\n";
echo "  Tier: Tier {$round['tier_number']} - {$round['tier_name']}\n";
echo "  Round Status: {$round['approval_status']}\n";
echo "  Created: {$round['created_at']}\n";
echo "  Completed: " . ($round['completed_at'] ?? 'Not yet') . "\n\n";

// Get approval slots and their status
$stmt = $db->prepare("
    SELECT 
        id as slot_instance_id,
        slot_number,
        slot_status,
        satisfied_by_user_id,
        satisfied_at,
        required_role,
        display_label,
        slot_type
    FROM transaction_approval_slot_instances
    WHERE approval_round_id = ?
    ORDER BY slot_number
");
$stmt->execute([$round['id']]);
$slots = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "APPROVAL PROGRESS:\n";
echo str_repeat("-", 80) . "\n";

$approvedCount = 0;
$pendingCount = 0;

foreach ($slots as $slot) {
    $slotLabel = "Slot {$slot['slot_number']}: {$slot['display_label']}";
    $status = $slot['slot_status'];
    
    if ($status === 'satisfied') {
        $approvedCount++;
        $approverUserId = $slot['satisfied_by_user_id'];
        $approvedAt = date('d M Y H:i', strtotime($slot['satisfied_at']));
        echo "  ✅ {$slotLabel}\n";
        echo "     Approved by: User ID {$approverUserId}\n";
        echo "     Approved at: {$approvedAt}\n\n";
    } else {
        $pendingCount++;
        $requiredRole = ucfirst(str_replace('_', ' ', $slot['required_role'] ?? 'Any authorized user'));
        echo "  ⏳ {$slotLabel}\n";
        echo "     Status: Pending\n";
        echo "     Required: {$requiredRole}\n\n";
    }
}

echo str_repeat("-", 80) . "\n";
echo "SUMMARY:\n";
echo "  Approved: {$approvedCount} / " . count($slots) . "\n";
echo "  Pending: {$pendingCount}\n\n";

if ($pendingCount > 0) {
    echo "⚠️  ACTION REQUIRED:\n";
    echo "   {$pendingCount} approval(s) still needed before this loan can be disbursed.\n\n";
    
    // Show who can approve
    $pendingRoles = [];
    foreach ($slots as $slot) {
        if ($slot['slot_status'] === 'pending') {
            $role = $slot['required_role'] ?? 'authorized_user';
            if (!in_array($role, $pendingRoles)) {
                $pendingRoles[] = $role;
            }
        }
    }
    
    if (!empty($pendingRoles)) {
        echo "   Users with these roles can approve:\n";
        foreach ($pendingRoles as $role) {
            echo "   - " . ucfirst(str_replace('_', ' ', $role)) . "\n";
        }
        echo "\n";
    }
} else {
    echo "✅ All approvals complete!\n";
    echo "   The loan can now be disbursed by Treasurer, Cashier, or Loans Officer.\n\n";
}

echo str_repeat("=", 80) . "\n\n";
