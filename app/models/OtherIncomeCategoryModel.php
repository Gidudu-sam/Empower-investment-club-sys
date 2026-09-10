<?php
/**
 * OtherIncomeCategoryModel — Other Income category CRUD.
 * Mirrors ExpenseCategoryModel, but every gl_account_id set here must
 * reference an active accounts.type='income' account — enforced here as
 * a defensive check in addition to the UI only ever offering income
 * accounts in its selector (Stage 17 Part D §7-§8).
 */
class OtherIncomeCategoryModel extends Model
{
    protected string $table      = 'other_income_categories';
    protected string $primaryKey = 'id';

    public function activeCategories(): array
    {
        return $this->db->query("
            SELECT oic.*, a.code AS account_code, a.name AS account_name
            FROM `other_income_categories` oic
            LEFT JOIN `accounts` a ON a.id = oic.gl_account_id
            WHERE oic.is_active = 1
            ORDER BY oic.category_name
        ")->fetchAll();
    }

    public function allWithAccounts(): array
    {
        return $this->db->query("
            SELECT oic.*, a.code AS account_code, a.name AS account_name
            FROM `other_income_categories` oic
            LEFT JOIN `accounts` a ON a.id = oic.gl_account_id
            ORDER BY oic.category_name
        ")->fetchAll();
    }

    /**
     * Validate that a gl_account_id, if given, is an active income account.
     * Throws if it points anywhere else (asset/liability/equity/expense,
     * inactive, or nonexistent). NULL is allowed — a category without a
     * mapped account simply cannot be posted until one is set, same as
     * Expense Categories.
     */
    private function assertValidIncomeAccount(?int $glAccountId): void
    {
        if ($glAccountId === null) {
            return;
        }
        $account = (new AccountModel())->find($glAccountId);
        if (!$account) {
            throw new InvalidArgumentException('Selected GL account does not exist.');
        }
        if ($account['type'] !== 'income') {
            throw new InvalidArgumentException('Other Income categories may only be mapped to an income-type GL account.');
        }
        if (!$account['is_active']) {
            throw new InvalidArgumentException('Selected GL account is inactive.');
        }
    }

    public function create(array $data): int|false
    {
        $this->assertValidIncomeAccount(isset($data['gl_account_id']) ? (int)$data['gl_account_id'] : null);
        return parent::create($data);
    }

    public function update(int $id, array $data): bool
    {
        if (array_key_exists('gl_account_id', $data)) {
            $this->assertValidIncomeAccount($data['gl_account_id'] !== null ? (int)$data['gl_account_id'] : null);
        }
        return parent::update($id, $data);
    }
}
