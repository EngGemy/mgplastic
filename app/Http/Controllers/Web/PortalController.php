<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\WebsiteSetting;

class PortalController extends Controller
{
    public function index()
    {
        return view('website.portal', [
            'settings' => WebsiteSetting::instance(),
            'portals' => [
                [
                    'key' => 'distributor',
                    'title' => 'موزع الجملة',
                    'subtitle' => 'WHOLESALE DISTRIBUTOR',
                    'description' => 'إدارة شبكة التوزيع، الفواتير، والموزعين القطاعيين.',
                    'url' => url('/distributor'),
                    'icon' => 'ti-truck',
                    'color' => '#1a56db',
                    'bg' => '#dbeafe',
                ],
                [
                    'key' => 'trader',
                    'title' => 'تاجر القطاعي',
                    'subtitle' => 'RETAIL TRADER',
                    'description' => 'نقطة البيع، توزيع النقاط، ومتابعة السباكين.',
                    'url' => url('/trader'),
                    'icon' => 'ti-building-store',
                    'color' => '#059669',
                    'bg' => '#d1fae5',
                ],
                [
                    'key' => 'plumber',
                    'title' => 'سباك',
                    'subtitle' => 'PLUMBER',
                    'description' => 'تطبيق الجوال لإدارة النقاط، المحفظة، وطلبات السحب.',
                    'url' => config('portal.plumber_app_url') ?: route('landing').'#register',
                    'icon' => 'ti-tool',
                    'color' => '#d97706',
                    'bg' => '#fde68a',
                    'external' => (bool) config('portal.plumber_app_url'),
                ],
            ],
        ]);
    }
}
