USE rice_business;

-- Priced inventory stacks (lots) so one product can hold stock at different buy prices

CREATE TABLE IF NOT EXISTS stock_lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  purchase_item_id INT UNSIGNED DEFAULT NULL,
  buying_price DECIMAL(10, 2) NOT NULL,
  quantity_original DECIMAL(10, 2) NOT NULL,
  quantity_remaining DECIMAL(10, 2) NOT NULL,
  purchased_at DATE NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_lots_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_lots_purchase_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id) ON DELETE SET NULL,
  INDEX idx_stock_lots_product_remaining (product_id, quantity_remaining),
  INDEX idx_stock_lots_purchase_item (purchase_item_id)
) ENGINE=InnoDB;

-- Snapshot cost on each sale line; link to the stack sold from
ALTER TABLE sale_items
  ADD COLUMN stock_lot_id INT UNSIGNED DEFAULT NULL AFTER product_id,
  ADD COLUMN cost_price DECIMAL(10, 2) DEFAULT NULL AFTER price;

ALTER TABLE sale_items
  ADD CONSTRAINT fk_sale_items_stock_lot FOREIGN KEY (stock_lot_id) REFERENCES stock_lots(id);

-- Opening lots for current on-hand stock (run once; skip products that already have lots)
INSERT INTO stock_lots (product_id, purchase_item_id, buying_price, quantity_original, quantity_remaining, purchased_at, notes)
SELECT
  p.id,
  NULL,
  p.buying_price,
  p.stock,
  p.stock,
  CURDATE(),
  'Opening stock'
FROM products p
WHERE p.stock > 0
  AND NOT EXISTS (
    SELECT 1 FROM stock_lots sl WHERE sl.product_id = p.id
  );
