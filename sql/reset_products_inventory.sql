USE rice_business;

-- Clear old product / inventory / sales data.
-- Keep: purchases (cost + batch), rice_names, batch_counters, suppliers, customers, expenses, etc.

SET FOREIGN_KEY_CHECKS = 0;

DELETE FROM stock_lot_writeoffs;
DELETE FROM stock_lot_components;
DELETE FROM stock_lots;
DELETE FROM stock_movements;

DELETE FROM sale_items;
DELETE FROM sales;

UPDATE purchase_items SET product_id = NULL;

DELETE FROM products;

SET FOREIGN_KEY_CHECKS = 1;

UPDATE batch_counters SET next_batch_no = 1 WHERE id = 1;
