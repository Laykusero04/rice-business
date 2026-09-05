-- Link sell batches (stock_lots) to the purchase batch that funded the mix.
ALTER TABLE stock_lots
  ADD COLUMN source_purchase_id INT UNSIGNED NULL AFTER purchase_item_id;

ALTER TABLE stock_lots
  ADD INDEX idx_stock_lots_source_purchase (source_purchase_id);

-- Optional FK (skip if purchases table engine/charset mismatch):
-- ALTER TABLE stock_lots
--   ADD CONSTRAINT fk_stock_lots_source_purchase
--   FOREIGN KEY (source_purchase_id) REFERENCES purchases(id) ON DELETE SET NULL;
