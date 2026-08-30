USE rice_business;

CREATE TABLE IF NOT EXISTS gcash_fee_tiers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  min_amount DECIMAL(12, 2) NOT NULL,
  max_amount DECIMAL(12, 2) NOT NULL,
  fee DECIMAL(12, 2) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_gcash_fee_range (min_amount, max_amount)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS gcash_cashins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  txn_type ENUM('cash_in', 'cash_out') NOT NULL DEFAULT 'cash_in',
  cashin_amount DECIMAL(12, 2) NOT NULL,
  suggested_fee DECIMAL(12, 2) NOT NULL,
  fee_charged DECIMAL(12, 2) NOT NULL,
  total_collected DECIMAL(12, 2) NOT NULL,
  customer_name VARCHAR(100) DEFAULT NULL,
  gcash_number VARCHAR(30) DEFAULT NULL,
  reference_no VARCHAR(100) DEFAULT NULL,
  notes VARCHAR(255) DEFAULT NULL,
  cashin_date DATE NOT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_gcash_cashins_user FOREIGN KEY (user_id) REFERENCES users(id),
  INDEX idx_gcash_cashins_date (cashin_date),
  INDEX idx_gcash_cashins_type (txn_type)
) ENGINE=InnoDB;

INSERT INTO gcash_fee_tiers (min_amount, max_amount, fee, sort_order)
SELECT * FROM (
  SELECT 1 AS min_amount, 500 AS max_amount, 5 AS fee, 1 AS sort_order
  UNION ALL SELECT 501, 1000, 10, 2
  UNION ALL SELECT 1001, 2000, 20, 3
  UNION ALL SELECT 2001, 3000, 30, 4
  UNION ALL SELECT 3001, 4000, 40, 5
  UNION ALL SELECT 4001, 5000, 50, 6
  UNION ALL SELECT 5001, 6000, 60, 7
  UNION ALL SELECT 6001, 7000, 70, 8
  UNION ALL SELECT 7001, 8000, 80, 9
  UNION ALL SELECT 8001, 9000, 90, 10
  UNION ALL SELECT 9001, 10000, 100, 11
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM gcash_fee_tiers LIMIT 1);
