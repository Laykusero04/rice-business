-- Optional: purchase → intended sell product (for profit linking).
ALTER TABLE purchases
  ADD COLUMN for_product_id INT UNSIGNED NULL AFTER batch_label;
