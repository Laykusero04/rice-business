USE rice_business;

CREATE TABLE IF NOT EXISTS expenses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(50) NOT NULL,
  amount DECIMAL(12, 2) NOT NULL,
  expense_date DATE NOT NULL,
  notes TEXT DEFAULT NULL,
  user_id INT UNSIGNED DEFAULT NULL,
  purchase_id INT UNSIGNED DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_expenses_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_expenses_purchase FOREIGN KEY (purchase_id) REFERENCES purchases(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO expenses (category, amount, expense_date, notes)
SELECT * FROM (
  SELECT 'Delivery' AS category, 500.00 AS amount, CURDATE() AS expense_date, 'Local delivery fuel' AS notes
  UNION ALL
  SELECT 'Electricity', 2500.00, CURDATE(), 'Monthly bill'
  UNION ALL
  SELECT 'Fuel', 1200.00, CURDATE(), 'Truck refill'
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM expenses LIMIT 1);
