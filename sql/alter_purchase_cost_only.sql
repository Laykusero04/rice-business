USE rice_business;

-- Purchase batch lives on the purchase (whole buy), not on product stock.
ALTER TABLE purchases
  ADD COLUMN batch_label VARCHAR(255) DEFAULT NULL AFTER notes;

-- Purchase lines are what you bought (raw names), not sellable product stock.
ALTER TABLE purchase_items
  ADD COLUMN item_name VARCHAR(100) DEFAULT NULL AFTER product_id,
  ADD COLUMN kg_per_sack DECIMAL(10, 2) NOT NULL DEFAULT 25.00 AFTER subtotal;

ALTER TABLE purchase_items
  MODIFY product_id INT UNSIGNED NULL;

-- Backfill item names from linked products (older purchases).
UPDATE purchase_items pi
INNER JOIN products pr ON pr.id = pi.product_id
SET pi.item_name = pr.name
WHERE pi.item_name IS NULL OR pi.item_name = '';

UPDATE purchase_items pi
INNER JOIN products pr ON pr.id = pi.product_id
SET pi.kg_per_sack = COALESCE(NULLIF(pr.kg_per_sack, 0), 25)
WHERE pi.kg_per_sack IS NULL OR pi.kg_per_sack <= 0;

-- Backfill purchase batch from first linked stock lot label when present.
UPDATE purchases p
INNER JOIN (
  SELECT pi.purchase_id, MIN(sl.notes) AS batch_label
  FROM purchase_items pi
  INNER JOIN stock_lots sl ON sl.purchase_item_id = pi.id
  WHERE sl.notes IS NOT NULL AND sl.notes <> ''
  GROUP BY pi.purchase_id
) x ON x.purchase_id = p.id
SET p.batch_label = x.batch_label
WHERE p.batch_label IS NULL OR p.batch_label = '';

-- Saved rice names for purchase dropdown (not sellable products / stock).
CREATE TABLE IF NOT EXISTS rice_names (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kg_per_sack DECIMAL(10, 2) NOT NULL DEFAULT 25.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_rice_name (name)
) ENGINE=InnoDB;

INSERT IGNORE INTO rice_names (name, kg_per_sack)
SELECT name, COALESCE(NULLIF(kg_per_sack, 0), 25)
FROM products
WHERE product_type = 'RICE' AND status = 'active';
