USE rice_business;

-- Run once on existing databases
ALTER TABLE sales
  ADD COLUMN amount_paid DECIMAL(12, 2) NOT NULL DEFAULT 0.00 AFTER total,
  ADD COLUMN payment_status ENUM('paid', 'unpaid', 'partial') NOT NULL DEFAULT 'paid' AFTER amount_paid;

UPDATE sales SET amount_paid = total, payment_status = 'paid' WHERE payment_method <> 'credit';
UPDATE sales SET amount_paid = 0, payment_status = 'unpaid' WHERE payment_method = 'credit';
