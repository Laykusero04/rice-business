USE rice_business;

ALTER TABLE gcash_cashins
  ADD COLUMN txn_type ENUM('cash_in', 'cash_out') NOT NULL DEFAULT 'cash_in' AFTER id;

UPDATE gcash_cashins SET txn_type = 'cash_in' WHERE txn_type IS NULL OR txn_type = '';
