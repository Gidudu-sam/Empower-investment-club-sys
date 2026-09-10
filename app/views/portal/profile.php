<h3 class="fw-bold mb-3"><i class="bi bi-person me-2"></i>My Profile</h3>

<div class="card">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-6">
                <div class="detail-label">Member Number</div>
                <div class="detail-value"><?= htmlspecialchars($member['member_number'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Status</div>
                <div class="detail-value text-capitalize"><?= htmlspecialchars($member['status'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Full Name</div>
                <div class="detail-value"><?= htmlspecialchars(trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''))) ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Gender</div>
                <div class="detail-value"><?= htmlspecialchars($member['gender'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Phone</div>
                <div class="detail-value"><?= htmlspecialchars($member['phone'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Email</div>
                <div class="detail-value"><?= htmlspecialchars($member['email'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">National ID</div>
                <div class="detail-value"><?= htmlspecialchars($member['national_id'] ?? '—') ?></div>
            </div>
            <div class="col-md-6">
                <div class="detail-label">Join Date</div>
                <div class="detail-value"><?= !empty($member['join_date']) ? date('d M Y', strtotime($member['join_date'])) : '—' ?></div>
            </div>
        </div>
        <p class="text-muted small mt-4 mb-0">
            <i class="bi bi-info-circle me-1"></i>To update any of these details, please contact the office.
        </p>
    </div>
</div>
