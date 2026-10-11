-- =============================================================================
-- MG Plastic — Wipe ALL products + network inventory (phpMyAdmin)
-- =============================================================================
-- Run the WHOLE script in one go (SQL tab). Do not run single TRUNCATE lines.
--
-- Why not TRUNCATE alone?
--   invoice_returns is referenced by invoice_return_items → TRUNCATE fails with FK error.
--   This script uses DELETE + FOREIGN_KEY_CHECKS=0 (phpMyAdmin-safe).
--
-- CLEARS: products, invoice/distribution/return chain, orders, store_media.product_id
-- KEEPS:  users, stores, categories, wallets (optional wallet wipe at bottom)
-- =============================================================================

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Returns
DELETE FROM `invoice_return_items`;
DELETE FROM `invoice_returns`;

-- 2) Distributions (network stock trail)
DELETE FROM `invoice_distribution_items`;
DELETE FROM `invoice_distributions`;

-- 3) Orders
DELETE FROM `order_items`;
DELETE FROM `orders`;

-- 4) Invoices
DELETE FROM `invoice_items`;
DELETE FROM `invoices`;

-- 5) Detach media from products
UPDATE `store_media` SET `product_id` = NULL WHERE `product_id` IS NOT NULL;

-- 6) Products
DELETE FROM `product_sizes`;
DELETE FROM `product_variants`;
DELETE FROM `product_images`;
DELETE FROM `product_translations`;
DELETE FROM `products`;

SET FOREIGN_KEY_CHECKS = 1;

-- Reset AUTO_INCREMENT (optional, nice for a clean catalog restart)
ALTER TABLE `invoice_return_items` AUTO_INCREMENT = 1;
ALTER TABLE `invoice_returns` AUTO_INCREMENT = 1;
ALTER TABLE `invoice_distribution_items` AUTO_INCREMENT = 1;
ALTER TABLE `invoice_distributions` AUTO_INCREMENT = 1;
ALTER TABLE `order_items` AUTO_INCREMENT = 1;
ALTER TABLE `orders` AUTO_INCREMENT = 1;
ALTER TABLE `invoice_items` AUTO_INCREMENT = 1;
ALTER TABLE `invoices` AUTO_INCREMENT = 1;
ALTER TABLE `product_sizes` AUTO_INCREMENT = 1;
ALTER TABLE `product_variants` AUTO_INCREMENT = 1;
ALTER TABLE `product_images` AUTO_INCREMENT = 1;
ALTER TABLE `product_translations` AUTO_INCREMENT = 1;
ALTER TABLE `products` AUTO_INCREMENT = 1;

-- Verify:
-- SELECT COUNT(*) FROM products;
-- SELECT COUNT(*) FROM invoices;
-- SELECT COUNT(*) FROM invoice_distributions;

-- OPTIONAL — also zero wallets / points (uncomment if needed):
-- SET FOREIGN_KEY_CHECKS = 0;
-- DELETE FROM `wallet_transactions`;
-- DELETE FROM `points_ledger`;
-- UPDATE `wallet_accounts` SET `balance_points` = 0, `balance_cents` = 0;
-- SET FOREIGN_KEY_CHECKS = 1;
