<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppFlag;
use App\Models\ConversionRule;
use App\Models\SystemLabel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SettingsController extends Controller
{
    use ApiResponds;

    public function app(Request $request): JsonResponse
    {
        $rule = ConversionRule::globalSettings();

        return $this->success([
            'app_name' => config('app.name', 'MG Plastic'),
            'currency' => 'LYD',
            'currency_label' => 'د.ل',
            'supported_mobile_roles' => ['plumber', 'vendor', 'retail_trader', 'wholesale_distributor'],
            'conversion' => [
                'is_active' => $rule->is_active,
                'is_redemption_open' => $rule->isRedemptionOpen(),
                'points_per_currency_unit' => (float) $rule->points_per_currency_unit,
                'min_redeem_points' => (int) $rule->min_redeem_points,
                'max_redeem_points' => $rule->max_redeem_points ? (int) $rule->max_redeem_points : null,
                'fee_percent' => (float) $rule->fee_percent,
                'fee_fixed_cents' => (int) $rule->fee_fixed_cents,
                'starts_at' => $rule->starts_at?->toIso8601String(),
                'ends_at' => $rule->ends_at?->toIso8601String(),
                'notification_message_ar' => $rule->notification_message_ar,
            ],
            'withdrawal_methods' => [
                ['key' => 'bank_transfer', 'label' => 'تحويل بنكي'],
                ['key' => 'mobile_wallet', 'label' => 'محفظة إلكترونية'],
            ],
            'labels' => [
                'stores' => SystemLabel::get('stores', 'المتاجر'),
                'invoices' => SystemLabel::get('invoices', 'الفواتير'),
                'withdrawals' => SystemLabel::get('withdrawal_requests', 'طلبات السحب'),
            ],
            'links' => [
                'terms' => url('/api/v1/terms'),
                'privacy' => url('/api/v1/privacy'),
            ],
            'ios_wallet_visibility' => [
                'show_wallet' => AppFlag::getBool('ios_wallet_enabled', true),
            ],
        ]);
    }
}
