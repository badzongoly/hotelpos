-- Baseline seed data for a fresh hotelpos installation.
--
-- Security note:
-- This migration intentionally does not create a default administrator with a shared
-- password. After running migrations, create the first administrator locally with:
--   C:\xampp\php\php.exe tools\create_admin.php

INSERT INTO rooms(name, type, rate, status, active, occupancy_counted, sort_order)
SELECT * FROM (
  SELECT '01-Accra' AS name, 'Double' AS type, 150.00 AS rate, 'vacant' AS status, 1 AS active, 1 AS occupancy_counted, 1 AS sort_order UNION ALL
  SELECT '02-Cape Town', 'Queen', 200.00, 'vacant', 1, 1, 2 UNION ALL
  SELECT '03-Nairobi', 'Double', 150.00, 'vacant', 1, 1, 3 UNION ALL
  SELECT '04-Kigali', 'Queen', 200.00, 'vacant', 1, 1, 4 UNION ALL
  SELECT '05-Johannesburg', 'Queen', 200.00, 'vacant', 1, 1, 5 UNION ALL
  SELECT '06-Amsterdam', 'Double', 150.00, 'vacant', 1, 1, 6 UNION ALL
  SELECT '07-Copenhagen', 'Standard', 120.00, 'vacant', 1, 1, 7 UNION ALL
  SELECT 'Lawn Rental', 'Service', 500.00, 'vacant', 1, 0, 8 UNION ALL
  SELECT 'Walk-in', 'Service', 0.00, 'vacant', 1, 0, 9
) seed
WHERE NOT EXISTS (SELECT 1 FROM rooms);

INSERT INTO expense_categories(name, active)
SELECT name, 1 FROM (
  SELECT 'Utilities' name UNION ALL SELECT 'Supplies' UNION ALL SELECT 'Maintenance' UNION ALL
  SELECT 'Staff' UNION ALL SELECT 'Marketing' UNION ALL SELECT 'Other' UNION ALL
  SELECT 'Extras' UNION ALL SELECT 'Toiletries' UNION ALL SELECT 'CAPEX'
) seed
WHERE NOT EXISTS (SELECT 1 FROM expense_categories);

INSERT INTO extras(name, price, active, stock_tracked, stock_qty)
SELECT * FROM (
  SELECT 'Malt' AS name, 20.00 AS price, 1 AS active, 1 AS stock_tracked, 0.00 AS stock_qty UNION ALL
  SELECT 'Club', 20.00, 1, 1, 0.00 UNION ALL
  SELECT 'Vody', 25.00, 1, 1, 0.00 UNION ALL
  SELECT 'Guinness can', 20.00, 1, 1, 0.00 UNION ALL
  SELECT 'Bottle water', 5.00, 1, 1, 0.00
) seed
WHERE NOT EXISTS (SELECT 1 FROM extras);

INSERT INTO settings(setting_key, setting_value, updated_at)
VALUES
  ('currency_code', 'GHS', UTC_TIMESTAMP()),
  ('allow_negative_stock', '0', UTC_TIMESTAMP()),
  ('checkout_requires_zero_balance', '1', UTC_TIMESTAMP())
ON DUPLICATE KEY UPDATE setting_key = setting_key;
