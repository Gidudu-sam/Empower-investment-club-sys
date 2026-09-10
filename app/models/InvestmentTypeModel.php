<?php
/**
 * InvestmentTypeModel — admin-configurable GL mapping per investment type.
 * Not part of the immutable ledger — only posted investments/transactions
 * and journal entries are — so ordinary update()/delete() are fine here.
 */
class InvestmentTypeModel extends Model
{
    protected string $table      = 'investment_types';
    protected string $primaryKey = 'id';

    public function activeTypes(): array
    {
        return $this->db->query("
            SELECT it.*, aa.code AS asset_code, aa.name AS asset_name,
                   ai.code AS income_code, ai.name AS income_name,
                   al.code AS loss_code, al.name AS loss_name
            FROM `investment_types` it
            JOIN `accounts` aa ON aa.id = it.asset_gl_account_id
            JOIN `accounts` ai ON ai.id = it.income_gl_account_id
            LEFT JOIN `accounts` al ON al.id = it.loss_gl_account_id
            WHERE it.is_active = 1
            ORDER BY it.type_name
        ")->fetchAll();
    }

    public function allWithAccounts(): array
    {
        return $this->db->query("
            SELECT it.*, aa.code AS asset_code, aa.name AS asset_name,
                   ai.code AS income_code, ai.name AS income_name,
                   al.code AS loss_code, al.name AS loss_name
            FROM `investment_types` it
            JOIN `accounts` aa ON aa.id = it.asset_gl_account_id
            JOIN `accounts` ai ON ai.id = it.income_gl_account_id
            LEFT JOIN `accounts` al ON al.id = it.loss_gl_account_id
            ORDER BY it.type_name
        ")->fetchAll();
    }
}
