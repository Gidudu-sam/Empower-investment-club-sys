<?php
/**
 * ExpenseCategoryModel — expense category CRUD.
 * Categories are not part of the immutable ledger — only posted expenses
 * and journal entries are — so ordinary update()/delete() are fine here.
 */
class ExpenseCategoryModel extends Model
{
    protected string $table      = 'expense_categories';
    protected string $primaryKey = 'id';

    public function activeCategories(): array
    {
        return $this->db->query("
            SELECT ec.*, a.code AS account_code, a.name AS account_name
            FROM `expense_categories` ec
            LEFT JOIN `accounts` a ON a.id = ec.gl_account_id
            WHERE ec.is_active = 1
            ORDER BY ec.category_group, ec.category_name
        ")->fetchAll();
    }

    /**
     * Return active categories grouped for use in a <select> with <optgroup>.
     * Returns: [ 'Group Name' => [ [...row...], [...row...] ], ... ]
     */
    public function activeCategoriesGrouped(): array
    {
        $rows = $this->activeCategories();
        $grouped = [];
        foreach ($rows as $row) {
            $group = $row['category_group'] ?: 'Other';
            $grouped[$group][] = $row;
        }
        return $grouped;
    }

    public function allWithAccounts(): array
    {
        return $this->db->query("
            SELECT ec.*, a.code AS account_code, a.name AS account_name
            FROM `expense_categories` ec
            LEFT JOIN `accounts` a ON a.id = ec.gl_account_id
            ORDER BY ec.category_group, ec.category_name
        ")->fetchAll();
    }
}
