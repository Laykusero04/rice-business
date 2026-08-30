USE rice_business;

CREATE TABLE IF NOT EXISTS products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  product_type ENUM('RICE', 'GROCERY') NOT NULL DEFAULT 'RICE',
  category VARCHAR(50) NOT NULL,
  unit ENUM('kg', 'pc', 'L', 'ml') NOT NULL DEFAULT 'kg',
  buying_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  selling_price DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  selling_price_sack DECIMAL(10, 2) DEFAULT NULL,
  kg_per_sack DECIMAL(10, 2) NOT NULL DEFAULT 25.00,
  stock DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  minimum_stock DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO products (name, product_type, category, unit, buying_price, selling_price, kg_per_sack, stock, minimum_stock, status)
SELECT * FROM (
  SELECT 'Dinorado' AS name, 'RICE' AS product_type, 'Premium' AS category, 'kg' AS unit, 45.00 AS buying_price, 55.00 AS selling_price, 25.00 AS kg_per_sack, 350.00 AS stock, 50.00 AS minimum_stock, 'active' AS status
  UNION ALL
  SELECT 'Jasmine', 'RICE', 'Jasmine', 'kg', 50.00, 62.00, 25.00, 120.00, 40.00, 'active'
  UNION ALL
  SELECT 'Sinandomeng', 'RICE', 'Regular', 'kg', 38.00, 48.00, 25.00, 500.00, 80.00, 'active'
  UNION ALL
  SELECT 'Egg', 'GROCERY', 'Eggs', 'pc', 6.00, 8.00, 25.00, 120.00, 30.00, 'active'
  UNION ALL
  SELECT 'Cooking Oil', 'GROCERY', 'Oil', 'L', 70.00, 90.00, 25.00, 30.00, 5.00, 'active'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM products LIMIT 1);
