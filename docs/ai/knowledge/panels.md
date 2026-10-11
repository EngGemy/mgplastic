# لوحات التحكم

## الأدمن
- المسار من `.env`: `ADMIN_PANEL_PATH` (مثال إنتاج: قيمة مخفية مثل `mg-cp-...`).
- Filament panel id: `admin`
- الشعار: `public/images/logo-light.png`
- المحتوى العام للموقع يُدار من صفحة **إعدادات الموقع** (`ManageWebsiteSettings`).

## موزّع الجملة
- المسار: `/distributor`
- طلبات، فواتير، توزيعات، تجار قطاعي، متجري، POS.

## التاجر القطاعي
- المسار: `/trader`
- طلبات السباكين، فواتير، توزيع نقاط، متجري، POS.

## الموقع العام
- Landing + portal + contact تستخدم `WebsiteSetting`.
- شعار الموقع = نفس شعار لوحة الأدمن (`images/logo-light.png`).
