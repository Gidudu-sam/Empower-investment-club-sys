-- Shares Module — Stage 3 (Historical / Opening Share Entry, 2026-09).
-- Purely additive: extends share_transactions.transaction_type with TWO new
-- values only. Every existing value is preserved unchanged, in the same
-- order, so no existing row (Stage 2 already has none in production, but
-- this is written to be safe even where it does) is reinterpreted.
--
-- opening_retained  = a member's pre-existing retained-savings share
--                      position, entered once as historical/opening data.
-- opening_purchase  = a member's pre-existing directly-purchased share
--                      position, entered once as historical/opening data.
--
-- Both are ownership/ledger records only (Option A, per the Stage 3 brief
-- Section 9) -- no journal_entry_id is ever populated for these two types;
-- see ShareModel::createHistoricalRetained()/createHistoricalPurchase().

ALTER TABLE `share_transactions`
    MODIFY COLUMN `transaction_type` ENUM(
        'retained_withdrawal',
        'direct_purchase',
        'transfer_in',
        'transfer_out',
        'redemption',
        'adjustment',
        'opening_retained',
        'opening_purchase'
    ) NOT NULL COMMENT 'opening_retained/opening_purchase added Stage 3 -- historical/opening entries only, never journal-linked';
