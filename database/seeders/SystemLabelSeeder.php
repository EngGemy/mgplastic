<?php

namespace Database\Seeders;

use App\Models\SystemLabel;
use Illuminate\Database\Seeder;

class SystemLabelSeeder extends Seeder
{
    public function run(): void
    {
        $labels = [
            // ── الأدوار ──────────────────────────────────
            ['key' => 'super_admin', 'default_value' => 'سوبر أدمن', 'group' => 'roles', 'description' => 'مسمى المدير الأعلى — يظهر في قائمة المستخدمين'],
            ['key' => 'wholesaler', 'default_value' => 'موزع جملة', 'group' => 'roles', 'description' => 'مسمى موزع الجملة'],
            ['key' => 'retail_trader', 'default_value' => 'تاجر قطاعي', 'group' => 'roles', 'description' => 'مسمى تاجر القطاعي'],
            ['key' => 'plumber', 'default_value' => 'سباك', 'group' => 'roles', 'description' => 'مسمى السباك — غيّره من «المسميات» ليظهر في كل النظام'],
            ['key' => 'vendor', 'default_value' => 'بائع', 'group' => 'roles', 'description' => 'مسمى البائع'],

            // ── التنقل (Sidebar + Resources) ───────────
            ['key' => 'dashboard', 'default_value' => 'لوحة التحكم', 'group' => 'navigation', 'description' => 'عنوان صفحة Dashboard'],
            ['key' => 'invoices', 'default_value' => 'الفواتير', 'group' => 'navigation', 'description' => 'قسم الفواتير في Sidebar والوصول السريع'],
            ['key' => 'distributions', 'default_value' => 'التوزيعات', 'group' => 'navigation', 'description' => 'قسم توزيعات الفواتير'],
            ['key' => 'point_rules', 'default_value' => 'قواعد النقاط', 'group' => 'navigation', 'description' => 'قسم قواعد النقاط'],
            ['key' => 'withdrawal_requests', 'default_value' => 'طلبات السحب', 'group' => 'navigation', 'description' => 'قسم طلبات السحب'],
            ['key' => 'products', 'default_value' => 'المنتجات', 'group' => 'navigation', 'description' => 'قسم المنتجات'],
            ['key' => 'users', 'default_value' => 'المستخدمون', 'group' => 'navigation', 'description' => 'قسم المستخدمين'],
            ['key' => 'stores', 'default_value' => 'المتاجر', 'group' => 'navigation', 'description' => 'قسم المتاجر'],
            ['key' => 'system_labels', 'default_value' => 'المسميات', 'group' => 'navigation', 'description' => 'صفحة تخصيص المسميات'],

            // ── الوصول السريع (Dashboard) ───────────────
            ['key' => 'quick_access_title', 'default_value' => 'الوصول السريع', 'group' => 'quick_access', 'description' => 'عنوان قسم الوصول السريع في Dashboard'],
            ['key' => 'quick_invoices_sub', 'default_value' => 'مراجعة واعتماد', 'group' => 'quick_access', 'description' => 'وصف زر الفواتير'],
            ['key' => 'quick_distributions_sub', 'default_value' => 'سلسلة توزيع النقاط', 'group' => 'quick_access', 'description' => 'وصف زر التوزيعات'],
            ['key' => 'quick_point_rules_sub', 'default_value' => 'تحديد قيمة النقطة', 'group' => 'quick_access', 'description' => 'وصف زر قواعد النقاط'],
            ['key' => 'quick_withdrawals_sub', 'default_value' => 'موافقة وصرف', 'group' => 'quick_access', 'description' => 'وصف زر طلبات السحب'],
            ['key' => 'quick_products_sub', 'default_value' => 'إدارة الكتالوج', 'group' => 'quick_access', 'description' => 'وصف زر المنتجات'],
            ['key' => 'quick_users_sub', 'default_value' => 'سباكون وموزعون', 'group' => 'quick_access', 'description' => 'وصف زر المستخدمين — يستخدم مسمى السباك'],
            ['key' => 'quick_stores_sub', 'default_value' => 'المتاجر والفروع', 'group' => 'quick_access', 'description' => 'وصف زر المتاجر'],
            ['key' => 'quick_labels_sub', 'default_value' => 'تخصيص النظام', 'group' => 'quick_access', 'description' => 'وصف زر المسميات'],

            // ── المالية ──────────────────────────────────
            ['key' => 'points', 'default_value' => 'نقاط', 'group' => 'finance', 'description' => 'مسمى النقاط في المحفظة والتوزيعات'],
            ['key' => 'wallet', 'default_value' => 'المحفظة', 'group' => 'finance', 'description' => 'مسمى المحفظة'],
            ['key' => 'currency', 'default_value' => 'دينار', 'group' => 'finance', 'description' => 'مسمى العملة'],
        ];

        foreach ($labels as $label) {
            SystemLabel::updateOrCreate(['key' => $label['key']], $label);
        }
    }
}
