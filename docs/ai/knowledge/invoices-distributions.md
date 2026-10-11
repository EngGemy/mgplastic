# الفواتير والتوزيعات والمرتجعات

## الفواتير (`Invoice`)
- حالات شائعة: `pending_review`, معتمدة، مرفوضة… حسب تدفق اللوحة.
- أنواع/تدفقات: وارد/صادر، جملة/قطاعي، مرتبطة بـ `plumber_id` أو `wholesale_distributor_id` أو `counterparty_user_id`.
- الحقول المالية بالـ cents: `subtotal_cents`, `tax_cents`, `total_cents`.

## التوزيعات (`InvoiceDistribution`)
- `from_user_id` → `to_user_id`
- `tier` مستوى الشبكة
- `status`: مثل `draft`, `confirmed`, `points_awarded`
- البنود في `invoice_distribution_items` وتحمل قيم النقاط.

## المرتجعات
خدمة `InvoiceReturnService` تدير مرتجعات الفواتير وتأثيرها على النقاط/المخزون حسب قواعد النظام.

## نصيحة للأدمن
عند سؤال "ليش النقاط ما وصلت؟" افحص: حالة الفاتورة، حالة التوزيع، وهل `points_awarded_at` موجود.
