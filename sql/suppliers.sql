USE rice_business;

CREATE TABLE IF NOT EXISTS suppliers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO suppliers (name, contact, address)
SELECT * FROM (
  SELECT 'Golden Grain Traders' AS name, '09171112233' AS contact, 'Nueva Ecija' AS address
  UNION ALL
  SELECT 'Isabela Rice Supply', '09185556677', 'Isabela'
  UNION ALL
  SELECT 'Central Luzon Mills', '09203334455', 'Tarlac'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM suppliers LIMIT 1);
