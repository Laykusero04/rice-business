USE rice_business;

-- Start over: clear batches / stock stacks. Keep products, purchases, sales history.

UPDATE sale_items SET stock_lot_id = NULL WHERE stock_lot_id IS NOT NULL;

DELETE FROM stock_lot_writeoffs;
DELETE FROM stock_lot_components;
DELETE FROM stock_lots;
DELETE FROM stock_movements;

UPDATE products SET stock = 0;

UPDATE batch_counters SET next_batch_no = 1 WHERE id = 1;
