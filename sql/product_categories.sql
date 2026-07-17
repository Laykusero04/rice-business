USE rice_business;

CREATE TABLE IF NOT EXISTS product_categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(50) NOT NULL,
  product_type ENUM('RICE', 'GROCERY') NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_product_category (product_type, name)
) ENGINE=InnoDB;

INSERT INTO product_categories (name, product_type)
SELECT * FROM (
  SELECT 'Premium' AS name, 'RICE' AS product_type
  UNION ALL SELECT 'Regular', 'RICE'
  UNION ALL SELECT 'Jasmine', 'RICE'
  UNION ALL SELECT 'Special', 'RICE'
  UNION ALL SELECT 'Eggs', 'GROCERY'
  UNION ALL SELECT 'Oil', 'GROCERY'
  UNION ALL SELECT 'Other', 'GROCERY'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM product_categories LIMIT 1);
