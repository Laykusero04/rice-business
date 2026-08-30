USE rice_business;

-- Batch / mix / rename / profit redesign columns on stock lots
ALTER TABLE stock_lots
  ADD COLUMN mill_name VARCHAR(255) DEFAULT NULL AFTER notes,
  ADD COLUMN total_cost DECIMAL(12, 2) DEFAULT NULL AFTER mill_name,
  ADD COLUMN weighed_kg DECIMAL(10, 2) DEFAULT NULL AFTER total_cost,
  ADD COLUMN lot_kind VARCHAR(20) NOT NULL DEFAULT 'purchase' AFTER weighed_kg,
  ADD COLUMN closed_at DATETIME DEFAULT NULL AFTER lot_kind;

UPDATE stock_lots
SET total_cost = ROUND(quantity_original * buying_price, 2)
WHERE total_cost IS NULL;

UPDATE stock_lots SET lot_kind = 'opening' WHERE notes = 'Opening stock' AND lot_kind = 'purchase';
UPDATE stock_lots SET lot_kind = 'adjustment'
WHERE notes IN ('Product stock sync', 'Inventory adjustment') AND lot_kind = 'purchase';

-- Mix recipe: output lot composed of source lots
CREATE TABLE IF NOT EXISTS stock_lot_components (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  mix_lot_id INT UNSIGNED NOT NULL,
  source_lot_id INT UNSIGNED NOT NULL,
  quantity_used DECIMAL(10, 2) NOT NULL,
  cost_amount DECIMAL(12, 2) NOT NULL,
  cost_share DECIMAL(10, 6) NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_slc_mix FOREIGN KEY (mix_lot_id) REFERENCES stock_lots(id),
  CONSTRAINT fk_slc_source FOREIGN KEY (source_lot_id) REFERENCES stock_lots(id),
  INDEX idx_slc_mix (mix_lot_id),
  INDEX idx_slc_source (source_lot_id)
) ENGINE=InnoDB;

-- Shrink / close-batch losses (for honest batch P&L)
CREATE TABLE IF NOT EXISTS stock_lot_writeoffs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  stock_lot_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10, 2) NOT NULL,
  cost_amount DECIMAL(12, 2) NOT NULL,
  reason VARCHAR(32) NOT NULL DEFAULT 'writeoff',
  movement_reference VARCHAR(100) DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_slw_lot FOREIGN KEY (stock_lot_id) REFERENCES stock_lots(id),
  INDEX idx_slw_lot (stock_lot_id),
  INDEX idx_slw_created (created_at)
) ENGINE=InnoDB;
