USE rice_business;

-- Whole-sack selling price for rice (small kg sell stays on selling_price)
ALTER TABLE products
  ADD COLUMN selling_price_sack DECIMAL(10, 2) DEFAULT NULL AFTER selling_price;

-- Seed from current per-kg sell × kg per sack for rice
UPDATE products
SET selling_price_sack = ROUND(selling_price * kg_per_sack, 2)
WHERE product_type = 'RICE'
  AND (selling_price_sack IS NULL OR selling_price_sack = 0)
  AND kg_per_sack > 0;
