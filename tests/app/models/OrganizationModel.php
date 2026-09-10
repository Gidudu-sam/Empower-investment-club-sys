<?php
/**
 * OrganizationModel — minimal corporate-entity records for Corporate
 * Savings Accounts. Not a CRM: name, registration/contact details, and
 * authorized representatives only, per the approved minimum scope.
 */
class OrganizationModel extends Model
{
    protected string $table      = 'organizations';
    protected string $primaryKey = 'id';

    public function createOrganization(array $data): int
    {
        if (empty(trim($data['name'] ?? ''))) {
            throw new InvalidArgumentException('Organization name is required.');
        }

        $id = $this->create([
            'name'                => trim($data['name']),
            'registration_number' => trim($data['registration_number'] ?? '') ?: null,
            'contact_phone'       => trim($data['contact_phone'] ?? '') ?: null,
            'contact_email'       => trim($data['contact_email'] ?? '') ?: null,
            'address'             => trim($data['address'] ?? '') ?: null,
        ]);
        if ($id === false) {
            throw new RuntimeException('Failed to create organization.');
        }
        return $id;
    }

    public function updateOrganization(int $id, array $data): bool
    {
        if (array_key_exists('name', $data) && trim($data['name']) === '') {
            throw new InvalidArgumentException('Organization name is required.');
        }
        return $this->update($id, $data);
    }

    public function getOrganization(int $id): array|false
    {
        return $this->find($id);
    }

    public function getOrganizations(): array
    {
        return $this->db->query("SELECT * FROM `organizations` ORDER BY `name`")->fetchAll();
    }

    public function addRepresentative(int $organizationId, array $data): int
    {
        if (!$this->find($organizationId)) {
            throw new InvalidArgumentException("Organization id {$organizationId} does not exist.");
        }
        if (empty(trim($data['full_name'] ?? ''))) {
            throw new InvalidArgumentException('Representative full name is required.');
        }
        if (!empty($data['member_id']) && !(new MemberModel())->find((int)$data['member_id'])) {
            throw new InvalidArgumentException("Member id {$data['member_id']} does not exist.");
        }

        $stmt = $this->db->prepare(
            "INSERT INTO `organization_representatives`
             (organization_id, member_id, full_name, phone, role, is_authorized_signatory)
             VALUES (?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $organizationId,
            $data['member_id'] ?? null,
            trim($data['full_name']),
            trim($data['phone'] ?? '') ?: null,
            trim($data['role'] ?? '') ?: null,
            isset($data['is_authorized_signatory']) ? (int)(bool)$data['is_authorized_signatory'] : 1,
        ]);
        return (int)$this->db->lastInsertId();
    }

    public function getRepresentatives(int $organizationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, m.member_number, m.first_name, m.last_name
             FROM `organization_representatives` r
             LEFT JOIN `members` m ON m.id = r.member_id
             WHERE r.organization_id = ?
             ORDER BY r.is_authorized_signatory DESC, r.full_name ASC"
        );
        $stmt->execute([$organizationId]);
        return $stmt->fetchAll();
    }

    public function updateRepresentative(int $id, array $data): bool
    {
        $allowed = ['full_name', 'phone', 'role', 'is_authorized_signatory', 'member_id'];
        $fields = array_intersect_key($data, array_flip($allowed));
        if (array_key_exists('full_name', $fields) && trim($fields['full_name']) === '') {
            throw new InvalidArgumentException('Representative full name is required.');
        }
        if (empty($fields)) {
            return false;
        }
        $set = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $stmt = $this->db->prepare("UPDATE `organization_representatives` SET {$set} WHERE id = ?");
        return $stmt->execute([...array_values($fields), $id]);
    }
}
