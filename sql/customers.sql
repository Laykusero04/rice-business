USE rice_business;

CREATE TABLE IF NOT EXISTS customers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  contact VARCHAR(50) DEFAULT NULL,
  address TEXT DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT INTO customers (name, contact, address, notes)
SELECT * FROM (
  SELECT 'Maria Santos' AS name, '09171234567' AS contact, 'Brgy. San Jose, City' AS address, 'Regular buyer of Dinorado' AS notes
  UNION ALL
  SELECT 'Juan Dela Cruz', '09189876543', 'Poblacion, Town Proper', 'Prefers Jasmine rice'
  UNION ALL
  SELECT 'Ana Reyes', '09201239876', 'Sitio Malaya', NULL
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM customers LIMIT 1);
