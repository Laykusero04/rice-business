USE rice_business;

-- Payment source on purchases (business funds vs personal money)
ALTER TABLE purchases
  ADD COLUMN payment_source ENUM('business', 'personal') NOT NULL DEFAULT 'business'
  AFTER purchase_date;

-- Link auto-created owner-investment expenses back to the purchase
ALTER TABLE expenses
  ADD COLUMN purchase_id INT UNSIGNED DEFAULT NULL AFTER user_id,
  ADD CONSTRAINT fk_expenses_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL;
