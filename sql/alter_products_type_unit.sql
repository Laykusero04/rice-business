USE rice_business;

-- Run once if products table already exists without product_type/unit columns
ALTER TABLE products
  ADD COLUMN product_type ENUM('RICE', 'GROCERY') NOT NULL DEFAULT 'RICE' AFTER name,
  ADD COLUMN unit ENUM('kg', 'pc', 'L', 'ml') NOT NULL DEFAULT 'kg' AFTER category;

-- Backfill existing rows (safe even if already correct)
UPDATE products
SET product_type = 'RICE', unit = 'kg'
WHERE product_type IS NULL OR unit IS NULL;

