-- =============================================================================
-- MG Plastic — Wipe app users and start clean (phpMyAdmin)
-- =============================================================================
-- Run the WHOLE script in one go (SQL tab). Take a database backup first.
--
-- DELETES: plumbers, retail traders, wholesale distributors, vendors, customers
--          their tokens, sessions, wallets, points, withdrawals,
--          orders, invoices, distributions, returns, store pages
-- KEEPS:   super_admin + admin (so /mg-cp still opens)
--          products, categories, website settings, price lists
-- =============================================================================

-- Preview before you run the deletes (optional):
-- SELECT role, COUNT(*) AS total FROM users GROUP BY role;

SET FOREIGN_KEY_CHECKS = 0;

-- 1) Logins
DELETE FROM `personal_access_tokens`
WHERE `tokenable_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

DELETE FROM `sessions`
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

DELETE FROM `password_reset_tokens`
WHERE `email` IN (
    SELECT `email` FROM `users`
    WHERE `role` NOT IN ('super_admin', 'admin') AND `email` IS NOT NULL
);

DELETE FROM `notifications`
WHERE `notifiable_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

-- 2) Wallets and points
DELETE FROM `wallet_transactions`;
DELETE FROM `points_ledger`;
DELETE FROM `withdrawal_requests`;
DELETE FROM `wallet_accounts`
WHERE `owner_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

-- 3) Orders, invoices, stock movement
DELETE FROM `invoice_return_items`;
DELETE FROM `invoice_returns`;
DELETE FROM `invoice_distribution_items`;
DELETE FROM `invoice_distributions`;
DELETE FROM `order_items`;
DELETE FROM `orders`;
DELETE FROM `invoice_items`;
DELETE FROM `invoices`;
DELETE FROM `wholesaler_retail_trader`;

-- 4) Store pages and plumber extras
DELETE FROM `store_media`
WHERE `owner_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

DELETE FROM `social_links`
WHERE `linkable_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

DELETE FROM `plumber_work_photos`
WHERE `plumber_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

DELETE FROM `plumber_store_images`
WHERE `plumber_store_id` IN (
    SELECT `id` FROM `plumber_stores`
    WHERE `vendor_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'))
);

DELETE FROM `plumber_store_translations`
WHERE `plumber_store_id` IN (
    SELECT `id` FROM `plumber_stores`
    WHERE `vendor_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'))
);

UPDATE `conversion_rules`
SET `vendor_store_id` = NULL
WHERE `vendor_store_id` IN (
    SELECT `id` FROM `plumber_stores`
    WHERE `vendor_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'))
);

DELETE FROM `plumber_stores`
WHERE `vendor_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

-- 5) Keep the product catalog, just detach it from deleted accounts
UPDATE `products`
SET `user_id` = NULL
WHERE `user_id` IN (SELECT `id` FROM `users` WHERE `role` NOT IN ('super_admin', 'admin'));

-- 6) App users
DELETE FROM `users`
WHERE `role` NOT IN ('super_admin', 'admin');

SET FOREIGN_KEY_CHECKS = 1;

-- Verify — app roles must be 0. super_admin / admin must still be there.
-- SELECT role, COUNT(*) AS total FROM users GROUP BY role;
-- SELECT COUNT(*) AS orders FROM orders;
-- SELECT COUNT(*) AS invoices FROM invoices;
-- SELECT COUNT(*) AS products FROM products;
