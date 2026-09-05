USE rice_business;

CREATE TABLE IF NOT EXISTS batch_counters (
  id TINYINT UNSIGNED NOT NULL PRIMARY KEY DEFAULT 1,
  next_batch_no INT UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT INTO batch_counters (id, next_batch_no)
SELECT 1, COALESCE(
  (
    SELECT MAX(CAST(SUBSTRING(notes, 7) AS UNSIGNED))
    FROM stock_lots
    WHERE notes REGEXP '^Batch-[0-9]+$'
  ),
  0
) + 1
WHERE NOT EXISTS (SELECT 1 FROM batch_counters WHERE id = 1);
