-- =============================================================================
-- MG Plastic — Wipe ALL products + network inventory (phpMyAdmin)
-- =============================================================================
-- What this clears:
--   • Products (+ translations / images / variants / sizes via CASCADE or explicit)
--   • Network stock at wholesalers & retail traders (derived from invoices/distributions)
--   • Invoice items, distributions, returns
--   • Orders & order items that reference products
--   • store_media.product_id links (media rows kept, product link nulled)
--
-- What this KEEPS:
--   • Users / stores / roles / network links
--   • Product categories / colors / standards / sizes catalog (size_systems, sizes)
--   • Website settings, blogs, events, etc.
--   • Wallet balances & wallet_transactions (OPTIONAL reset at bottom — commented)
--
-- BEFORE RUN:
--   1) Full DB backup / export
--   2) Put the site in maintenance if possible
--   3) Run in phpMyAdmin → SQL tab as ONE batch
-- =============================================================================

START TRANSACTION;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------------
-- 1) Returns (depend on distributions / invoice_items / products)
-- ---------------------------------------------------------------------------
TRUNCATE TABLE `invoice_return_items`;
TRUNCATE TABLE `invoice_returns`;

-- ---------------------------------------------------------------------------
-- 2) Distributions = stock movement between factory → wholesale → retail → plumber
-- ---------------------------------------------------------------------------
TRUNCATE TABLE `invoice_distribution_items`;
TRUNCATE TABLE `invoice_distributions`;

-- ---------------------------------------------------------------------------
-- 3) Orders (plumber ↔ retail / retail ↔ wholesale) that reference products
-- ---------------------------------------------------------------------------
TRUNCATE TABLE `order_items`;
TRUNCATE TABLE `orders`;

-- ---------------------------------------------------------------------------
-- 4) Invoice lines then invoices (source of wholesaler inventory)
-- ---------------------------------------------------------------------------
TRUNCATE TABLE `invoice_items`;
TRUNCATE TABLE `invoices`;

-- ---------------------------------------------------------------------------
-- 5) Detach store media from products (keep media files/rows)
-- ---------------------------------------------------------------------------
UPDATE `store_media` SET `product_id` = NULL WHERE `product_id` IS NOT NULL;

-- ---------------------------------------------------------------------------
-- 6) Product child tables then products
--     (CASCADE usually handles children; we truncate explicitly for a clean wipe)
-- ---------------------------------------------------------------------------
TRUNCATE TABLE `product_sizes`;
TRUNCATE TABLE `product_variants`;
TRUNCATE TABLE `product_images`;
TRUNCATE TABLE `product_translations`;
TRUNCATE TABLE `products`;

SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

-- =============================================================================
-- Verify (run after commit)
-- =============================================================================
-- SELECT COUNT(*) AS products FROM products;
-- SELECT COUNT(*) AS invoice_items FROM invoice_items;
-- SELECT COUNT(*) AS distributions FROM invoice_distributions;
-- SELECT COUNT(*) AS orders FROM orders;

-- =============================================================================
-- OPTIONAL — also zero wallets / points history (UNCOMMENT if you want a full
-- commercial reset, not only products/stock)
-- =============================================================================
-- SET FOREIGN_KEY_CHECKS = 0;
-- TRUNCATE TABLE `wallet_transactions`;
-- TRUNCATE TABLE `points_ledger`;
-- UPDATE `wallet_accounts` SET `balance_points` = 0, `balance_cents` = 0;
-- SET FOREIGN_KEY_CHECKS = 1;
