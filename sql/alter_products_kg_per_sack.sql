USE rice_business;

-- Run once if products table already exists without kg_per_sack
ALTER TABLE products
  ADD COLUMN kg_per_sack DECIMAL(10, 2) NOT NULL DEFAULT 25.00 AFTER selling_price;
