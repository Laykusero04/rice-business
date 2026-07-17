USE rice_business;

-- Run once if product_categories table does not exist yet
CREATE TABLE IF NOT EXISTS product_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  product_type ENUM('RICE', 'GROCERY') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product_category (product_type, name)
) ENGINE=InnoDB;

INSERT IGNORE INTO product_categories (name, product_type) VALUES
  ('Premium', 'RICE'),
  ('Regular', 'RICE'),
  ('Jasmine', 'RICE'),
  ('Special', 'RICE'),
  ('Eggs', 'GROCERY'),
  ('Oil', 'GROCERY'),
  ('Other', 'GROCERY');
