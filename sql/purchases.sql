USE rice_business;

CREATE TABLE IF NOT EXISTS purchases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id INT UNSIGNED NOT NULL,
  total DECIMAL(12, 2) NOT NULL DEFAULT 0.00,
  purchase_date DATE NOT NULL,
  payment_source ENUM('business', 'personal') NOT NULL DEFAULT 'business',
  notes TEXT DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_purchases_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id),
  CONSTRAINT fk_purchases_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS purchase_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  quantity DECIMAL(10, 2) NOT NULL,
  buying_price DECIMAL(10, 2) NOT NULL,
  subtotal DECIMAL(12, 2) NOT NULL,
  CONSTRAINT fk_purchase_items_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE CASCADE,
  CONSTRAINT fk_purchase_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  type ENUM('IN', 'OUT', 'ADJUSTMENT') NOT NULL,
  quantity DECIMAL(10, 2) NOT NULL,
  reference VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_movements_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS stock_lots (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  purchase_item_id INT UNSIGNED DEFAULT NULL,
  buying_price DECIMAL(10, 2) NOT NULL,
  quantity_original DECIMAL(10, 2) NOT NULL,
  quantity_remaining DECIMAL(10, 2) NOT NULL,
  purchased_at DATE NOT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  mill_name VARCHAR(255) DEFAULT NULL,
  total_cost DECIMAL(12, 2) DEFAULT NULL,
  weighed_kg DECIMAL(10, 2) DEFAULT NULL,
  lot_kind VARCHAR(20) NOT NULL DEFAULT 'purchase',
  closed_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_stock_lots_product FOREIGN KEY (product_id) REFERENCES products(id),
  CONSTRAINT fk_stock_lots_purchase_item FOREIGN KEY (purchase_item_id) REFERENCES purchase_items(id) ON DELETE SET NULL,
  INDEX idx_stock_lots_product_remaining (product_id, quantity_remaining),
  INDEX idx_stock_lots_purchase_item (purchase_item_id)
) ENGINE=InnoDB;

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
