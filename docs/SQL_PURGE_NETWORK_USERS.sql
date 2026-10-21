-- =============================================================================
-- MG Plastic — مسح شبكة التشغيل (سباك + قطاعي + جملة عادية)
-- يبقي: super_admin / admin / monitor + مخزن الدعوة
--       (role=wholesale_distributor AND is_central_warehouse=1)
-- يحذف: plumber / retail_trader / wholesale_distributor (غير المخزن)
--       + طلبات + فواتير + توزيعات + مرتجعات + محافظ + ضمانات + سمعة + روابط
--
-- MySQL/MariaDB — خذ Backup أولًا.
-- للمراجعة فقط: شغّل قسم PREVIEW ثم أوقف.
-- للتنفيذ: TRANSACTION … COMMIT (أو ROLLBACK للتراجع قبل الـ commit).
-- =============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;
START TRANSACTION;

DROP TEMPORARY TABLE IF EXISTS tmp_purge_users;
CREATE TEMPORARY TABLE tmp_purge_users (id BIGINT UNSIGNED PRIMARY KEY);

INSERT INTO tmp_purge_users (id)
SELECT id FROM users
WHERE role IN ('plumber', 'retail_trader')
   OR (role = 'wholesale_distributor' AND COALESCE(is_central_warehouse, 0) = 0);

-- نسخة ثانية لنفس الـ IDs (MySQL لا يسمح بإعادة فتح نفس الـ TEMP في بعض الـ DELETE)
DROP TEMPORARY TABLE IF EXISTS tmp_purge_users2;
CREATE TEMPORARY TABLE tmp_purge_users2 (id BIGINT UNSIGNED PRIMARY KEY);
INSERT INTO tmp_purge_users2 SELECT id FROM tmp_purge_users;

-- =============================================================================
-- PREVIEW — انسخ النتائج وتأكد قبل المتابعة
-- =============================================================================
SELECT 'users_by_role' AS q, role, COALESCE(is_central_warehouse,0) AS warehouse, COUNT(*) AS c
FROM users GROUP BY role, warehouse;

SELECT 'will_delete_users' AS q, COUNT(*) AS c FROM tmp_purge_users;

SELECT 'will_keep_users' AS q, u.id, u.name, u.role, COALESCE(u.is_central_warehouse,0) AS warehouse
FROM users u
LEFT JOIN tmp_purge_users t ON t.id = u.id
WHERE t.id IS NULL
ORDER BY u.role, u.id;

SELECT 'orders_to_delete' AS q, COUNT(*) AS c
FROM orders o
WHERE o.requester_id IN (SELECT id FROM tmp_purge_users)
   OR o.supplier_id  IN (SELECT id FROM tmp_purge_users);

SELECT 'invoices_to_delete' AS q, COUNT(*) AS c
FROM invoices i
WHERE i.plumber_id IN (SELECT id FROM tmp_purge_users)
   OR i.counterparty_user_id IN (SELECT id FROM tmp_purge_users)
   OR i.wholesale_distributor_id IN (SELECT id FROM tmp_purge_users);

-- لو الأرقام غلط: نفّذ ROLLBACK; وتوقّف هنا.
-- لو تمام: كمّل أوامر الحذف التالية ثم COMMIT;

-- -----------------------------------------------------------------------------
-- 1) ضمانات
-- -----------------------------------------------------------------------------
DELETE wci FROM warranty_claims wci
INNER JOIN warranties w ON w.id = wci.warranty_id
INNER JOIN tmp_purge_users t ON t.id = w.plumber_id;

DELETE wi FROM warranty_items wi
INNER JOIN warranties w ON w.id = wi.warranty_id
INNER JOIN tmp_purge_users t ON t.id = w.plumber_id;

DELETE w FROM warranties w
INNER JOIN tmp_purge_users t ON t.id = w.plumber_id;

-- -----------------------------------------------------------------------------
-- 2) مرتجعات
-- -----------------------------------------------------------------------------
DELETE iri FROM invoice_return_items iri
INNER JOIN invoice_returns ir ON ir.id = iri.invoice_return_id
WHERE ir.from_user_id IN (SELECT id FROM tmp_purge_users)
   OR ir.to_user_id   IN (SELECT id FROM tmp_purge_users2);

DELETE ir FROM invoice_returns ir
WHERE ir.from_user_id IN (SELECT id FROM tmp_purge_users)
   OR ir.to_user_id   IN (SELECT id FROM tmp_purge_users2);

-- -----------------------------------------------------------------------------
-- 3) توزيعات
-- -----------------------------------------------------------------------------
DELETE idi FROM invoice_distribution_items idi
INNER JOIN invoice_distributions d ON d.id = idi.distribution_id
WHERE d.from_user_id IN (SELECT id FROM tmp_purge_users)
   OR d.to_user_id   IN (SELECT id FROM tmp_purge_users2);

DELETE d FROM invoice_distributions d
WHERE d.from_user_id IN (SELECT id FROM tmp_purge_users)
   OR d.to_user_id   IN (SELECT id FROM tmp_purge_users2);

-- -----------------------------------------------------------------------------
-- 4) طلبات
-- -----------------------------------------------------------------------------
DELETE oi FROM order_items oi
INNER JOIN orders o ON o.id = oi.order_id
WHERE o.requester_id IN (SELECT id FROM tmp_purge_users)
   OR o.supplier_id  IN (SELECT id FROM tmp_purge_users2);

DELETE o FROM orders o
WHERE o.requester_id IN (SELECT id FROM tmp_purge_users)
   OR o.supplier_id  IN (SELECT id FROM tmp_purge_users2);

-- -----------------------------------------------------------------------------
-- 5) فواتير (فواتير المخزن من المصنع تبقى إذا wholesale_distributor_id = المخزن فقط)
-- -----------------------------------------------------------------------------
UPDATE orders o
INNER JOIN invoices i ON i.id = o.delivered_invoice_id
SET o.delivered_invoice_id = NULL
WHERE i.plumber_id IN (SELECT id FROM tmp_purge_users)
   OR i.counterparty_user_id IN (SELECT id FROM tmp_purge_users2)
   OR i.wholesale_distributor_id IN (SELECT id FROM tmp_purge_users);

DELETE ii FROM invoice_items ii
INNER JOIN invoices i ON i.id = ii.invoice_id
WHERE i.plumber_id IN (SELECT id FROM tmp_purge_users)
   OR i.counterparty_user_id IN (SELECT id FROM tmp_purge_users2)
   OR i.wholesale_distributor_id IN (SELECT id FROM tmp_purge_users);

DELETE i FROM invoices i
WHERE i.plumber_id IN (SELECT id FROM tmp_purge_users)
   OR i.counterparty_user_id IN (SELECT id FROM tmp_purge_users2)
   OR i.wholesale_distributor_id IN (SELECT id FROM tmp_purge_users);

-- -----------------------------------------------------------------------------
-- 6) روابط الشبكة
-- -----------------------------------------------------------------------------
DELETE FROM retail_trader_plumber
WHERE retail_trader_id IN (SELECT id FROM tmp_purge_users)
   OR plumber_id IN (SELECT id FROM tmp_purge_users2);

DELETE FROM wholesaler_retail_trader
WHERE wholesaler_id IN (SELECT id FROM tmp_purge_users)
   OR retail_trader_id IN (SELECT id FROM tmp_purge_users2);

-- -----------------------------------------------------------------------------
-- 7) سمعة / شارات / سلسلة / تذكيرات
-- -----------------------------------------------------------------------------
DELETE FROM plumber_score_events WHERE user_id IN (SELECT id FROM tmp_purge_users);
DELETE FROM plumber_scores WHERE user_id IN (SELECT id FROM tmp_purge_users);
DELETE FROM plumber_streaks WHERE user_id IN (SELECT id FROM tmp_purge_users);
DELETE FROM user_badges WHERE user_id IN (SELECT id FROM tmp_purge_users);
DELETE FROM reminder_logs WHERE user_id IN (SELECT id FROM tmp_purge_users);
DELETE FROM reminder_preferences WHERE user_id IN (SELECT id FROM tmp_purge_users);

-- -----------------------------------------------------------------------------
-- 8) محافظ / سحب / نقاط
-- -----------------------------------------------------------------------------
DELETE wt FROM wallet_transactions wt
INNER JOIN wallet_accounts wa ON wa.id = wt.wallet_account_id
INNER JOIN tmp_purge_users t ON t.id = wa.owner_id;

UPDATE wallet_transactions SET created_by = NULL
WHERE created_by IN (SELECT id FROM tmp_purge_users);

DELETE wr FROM withdrawal_requests wr
INNER JOIN tmp_purge_users t ON t.id = wr.plumber_id;

UPDATE withdrawal_requests SET reviewed_by = NULL
WHERE reviewed_by IN (SELECT id FROM tmp_purge_users);

DELETE wa FROM wallet_accounts wa
INNER JOIN tmp_purge_users t ON t.id = wa.owner_id;

DELETE pl FROM points_ledger pl
INNER JOIN tmp_purge_users t ON t.id = pl.plumber_id;

-- -----------------------------------------------------------------------------
-- 9) متاجر / ميديا
-- -----------------------------------------------------------------------------
DELETE psi FROM plumber_store_images psi
INNER JOIN plumber_stores ps ON ps.id = psi.plumber_store_id
INNER JOIN tmp_purge_users t ON t.id = ps.vendor_id;

DELETE pst FROM plumber_store_translations pst
INNER JOIN plumber_stores ps ON ps.id = pst.plumber_store_id
INNER JOIN tmp_purge_users t ON t.id = ps.vendor_id;

DELETE ps FROM plumber_stores ps
INNER JOIN tmp_purge_users t ON t.id = ps.vendor_id;

DELETE pwp FROM plumber_work_photos pwp
INNER JOIN tmp_purge_users t ON t.id = pwp.plumber_id;

DELETE FROM store_media
WHERE owner_id IN (SELECT id FROM tmp_purge_users)
  AND owner_type LIKE '%User%';

DELETE FROM social_links
WHERE linkable_id IN (SELECT id FROM tmp_purge_users)
  AND linkable_type LIKE '%User%';

-- -----------------------------------------------------------------------------
-- 10) جلسات / توكن / بصمة / إشعارات
-- -----------------------------------------------------------------------------
DELETE FROM personal_access_tokens
WHERE tokenable_id IN (SELECT id FROM tmp_purge_users)
  AND tokenable_type LIKE '%User%';

DELETE FROM sessions WHERE user_id IN (SELECT id FROM tmp_purge_users);

DELETE FROM biometric_devices WHERE user_id IN (SELECT id FROM tmp_purge_users);

DELETE FROM notifications
WHERE notifiable_id IN (SELECT id FROM tmp_purge_users)
  AND notifiable_type LIKE '%User%';

DELETE FROM security_audit_logs
WHERE actor_id IN (SELECT id FROM tmp_purge_users)
   OR (subject_type LIKE '%User%' AND subject_id IN (SELECT id FROM tmp_purge_users2));

-- واتساب: امسح سجلات العملاء فقط (اختياري)
DELETE FROM whatsapp_logs WHERE recipient_type = 'customer';

-- فك مراجع متبقية على جداول تبقى
UPDATE warranty_claims SET handled_by = NULL WHERE handled_by IN (SELECT id FROM tmp_purge_users);
UPDATE invoices SET issued_by = NULL WHERE issued_by IN (SELECT id FROM tmp_purge_users2);
UPDATE invoices SET reviewed_by = NULL WHERE reviewed_by IN (SELECT id FROM tmp_purge_users2);
UPDATE orders SET confirmed_by = NULL WHERE confirmed_by IN (SELECT id FROM tmp_purge_users2);
UPDATE orders SET shipped_by = NULL WHERE shipped_by IN (SELECT id FROM tmp_purge_users2);
UPDATE orders SET delivered_by = NULL WHERE delivered_by IN (SELECT id FROM tmp_purge_users2);
UPDATE orders SET created_by = NULL WHERE created_by IN (SELECT id FROM tmp_purge_users2);

-- -----------------------------------------------------------------------------
-- 11) حذف المستخدمين
-- -----------------------------------------------------------------------------
DELETE u FROM users u
INNER JOIN tmp_purge_users t ON t.id = u.id;

-- تحقق
SELECT 'after_purge' AS q, role, COALESCE(is_central_warehouse,0) AS warehouse, COUNT(*) AS c
FROM users GROUP BY role, warehouse;

-- لو تمام:
COMMIT;
-- لو لا:
-- ROLLBACK;

SET FOREIGN_KEY_CHECKS = 1;

DROP TEMPORARY TABLE IF EXISTS tmp_purge_users;
DROP TEMPORARY TABLE IF EXISTS tmp_purge_users2;

-- =============================================================================
-- ما لا يُمسَح: منتجات، تصنيفات، أدمن، مخزن، محتوى الموقع، testimonials، badges كتالوج
-- =============================================================================
