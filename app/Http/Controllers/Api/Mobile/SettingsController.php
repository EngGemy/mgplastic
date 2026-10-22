<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Api\Concerns\ApiResponds;
use App\Http\Controllers\Controller;
use App\Models\AppFlag;
use App\Models\ConversionRule;
use App\Models\SystemLabel;
use App\Models\WebsiteSetting;
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
            'support' => WebsiteSetting::instance()->supportContacts(),
            'links' => [
                'terms' => url('/api/v1/terms'),
                'privacy' => url('/api/v1/privacy'),
            ],
            'ios_wallet_visibility' => [
                'show_wallet' => AppFlag::getBool('ios_wallet_enabled', true),
            ],
            'otp' => [
                'channels' => app(\App\Services\PhoneOtpService::class)->channelOptions(),
                'ticket_ttl_minutes' => \App\Services\OtpTicketService::TTL_MINUTES,
                'endpoints' => [
                    'send' => url('/api/v1/auth/send-otp'),
                    'verify' => url('/api/v1/auth/verify-otp'),
                    'resend' => url('/api/v1/auth/resend-otp'),
                ],
                'forgot_password' => [
                    'send' => url('/api/v1/auth/forgot-password/send-otp'),
                    'verify' => url('/api/v1/auth/forgot-password/verify-otp'),
                    'reset' => url('/api/v1/auth/forgot-password/reset'),
                ],
            ],
            'biometric' => [
                'enabled' => true,
                'challenge_ttl_seconds' => 120,
                'methods' => ['unlock_token', 'public_key_signature'],
                'prompt_after_otp' => true,
                'endpoints' => [
                    'register' => url('/api/v1/mobile/biometric/register'),
                    'challenge' => url('/api/v1/mobile/biometric/challenge'),
                    'verify' => url('/api/v1/mobile/biometric/verify'),
                    'devices' => url('/api/v1/mobile/biometric/devices'),
                ],
            ],
            'reputation' => [
                'enabled' => (bool) config('reputation.enabled', true),
                'levels' => config('reputation.levels'),
                'weights' => config('reputation.weights'),
            ],
            'warranty' => [
                'enabled' => (bool) config('warranty.enabled', true),
                'min_level' => config('warranty.min_level', 'gold'),
                'min_score' => (int) config('warranty.min_score', 70),
                'min_distributions' => (int) config('warranty.min_distributions', 5),
                'default_months' => (int) config('warranty.default_months', 12),
            ],
            'reminders' => [
                'enabled' => (bool) config('reminders.enabled', false),
            ],
            'testimonials' => [
                'enabled' => (bool) config('testimonials.enabled', true),
                'endpoint' => url('/api/v1/mobile/testimonials'),
            ],
            'home' => [
                'highlights' => url('/api/v1/mobile/home/highlights'),
                'product_types' => [
                    'trader' => url('/api/v1/mobile/trader/products/types'),
                    'distributor' => url('/api/v1/mobile/distributor/products/types'),
                ],
                'systems' => [
                    'trader' => url('/api/v1/mobile/trader/products/categories').'?systems=1',
                    'distributor' => url('/api/v1/mobile/distributor/products/categories').'?systems=1',
                ],
                'sliders' => [
                    'home' => url('/api/v1/sliders/home'),
                    'store' => url('/api/v1/sliders/store'),
                ],
            ],
        ]);
    }
}
