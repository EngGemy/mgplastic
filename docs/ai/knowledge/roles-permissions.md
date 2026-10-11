# الأدوار والصلاحيات — MG Plastic

## الأدوار الأساسية
- `super_admin`: صلاحيات كاملة على لوحة التحكم، يتجاوز فحص permissions.
- `admin`: أدمن محدود حسب مصفوفة `users.permissions`.
- `wholesale_distributor`: موزّع جملة — لوحة `/distributor`.
- `retail_trader`: تاجر قطاعي — لوحة `/trader`.
- `plumber`: سباك — تطبيق الجوال.
- `vendor`: بائع متاجر السباكين (قديم/مكمل).

## صلاحيات الأدمن (`AdminPermissions`)
- `users.view` عرض المستخدمين والتقارير
- `users.manage` إضافة وتعديل المستخدمين
- `users.delete` حذف المستخدمين
- `products.manage` إدارة المنتجات والفئات
- `invoices.manage` إدارة الفواتير
- `distributions.manage` إدارة التوزيعات والنقاط
- `stores.manage` إدارة المتاجر والشبكة
- `settings.manage` إعدادات النظام
- `reports.view` عرض التقارير

إذا كانت `permissions` فارغة لمستخدم `admin` فغالباً يُعامل كأنه يملك كل الصلاحيات (حسب منطق `canAdminPermission`).

## الموافقات
حسابات الجملة/القطاعي/السباك قد تحتاج `is_approved=true` و`is_active=true` للظهور والعمل في الشبكة.
