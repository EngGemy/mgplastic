<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\NetworkStoreResource;
use App\Models\User;
use App\Services\AdminNotificationService;
use App\Support\AdminPanelPath;
use App\Traits\SendsMarsolSmsOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Store self-registration (network stores).
 *
 * - POST /api/v1/auth/register-retail  → retail_trader (تاجر التجزئة / القطاعي) — use this from the mobile store app
 * - POST /api/v1/auth/register-store   → wholesale_distributor (موزّع جملة) — kept for wholesale flows
 *
 * Accounts are created pending admin approval. OTP is sent; verify via /auth/verify-otp.
 * After login, manage profile via /api/v1/my-store/*.
 */
class StoreRegisterController extends Controller
{
    use SendsMarsolSmsOtp;

    /**
     * Mobile store registration: تاجر تجزئة / قطاعي فقط.
     */
    public function registerRetail(Request $request): JsonResponse
    {
        return $this->registerWithRole($request, 'retail_trader');
    }

    /**
     * Wholesale distributor self-registration (موزّع جملة).
     */
    public function register(Request $request): JsonResponse
    {
        return $this->registerWithRole($request, 'wholesale_distributor');
    }

    protected function registerWithRole(Request $request, string $role): JsonResponse
    {
        $data = $request->validate([
            'name'              => ['required', 'string', 'max:255'],
            'phone'             => ['required', 'string', 'max:50', 'unique:users,phone'],
            'email'             => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password'          => ['required', 'string', 'min:6', 'max:255'],

            'brand_name'        => ['nullable', 'string', 'max:150'],
            'address'           => ['nullable', 'string', 'max:500'],
            'store_description' => ['nullable', 'string'],
            'short_description' => ['nullable', 'string', 'max:500'],
            'long_description'  => ['nullable', 'string'],
            'website'           => ['nullable', 'url', 'max:500'],
            'country_id'        => ['nullable', 'integer', 'exists:countries,id'],
            'city_id'           => ['nullable', 'integer', 'exists:cities,id'],
            'latitude'          => ['nullable', 'numeric', 'between:-90,90'],
            'longitude'         => ['nullable', 'numeric', 'between:-180,180'],

            'profile_photo'     => ['nullable', 'image', 'max:4096'],
            'brand_logo'        => ['nullable', 'image', 'max:4096'],

            'social_links'                 => ['nullable', 'array'],
            'social_links.*.platform'      => ['required_with:social_links', 'string', 'max:32'],
            'social_links.*.url'           => ['required_with:social_links', 'url', 'max:500'],
            'social_links.*.sort_order'    => ['nullable', 'integer', 'min:0'],
        ]);

        $phone = $data['phone'];
        $isLibya = $this->isLibyaPhone($phone);
        $localOtp = $isLibya ? null : random_int(100000, 999999);

        $user = DB::transaction(function () use ($request, $data, $localOtp, $isLibya, $role) {
            $user = new User();
            $user->fill([
                'name'              => $data['name'],
                'email'             => $data['email'] ?? null,
                'phone'             => $data['phone'],
                'password'          => Hash::make($data['password']),
                'role'              => $role,
                'brand_name'        => $data['brand_name'] ?? null,
                'address'           => $data['address'] ?? null,
                'store_description' => $data['store_description'] ?? null,
                'short_description' => $data['short_description'] ?? null,
                'long_description'  => $data['long_description'] ?? null,
                'website'           => $data['website'] ?? null,
                'country_id'        => $data['country_id'] ?? null,
                'city_id'           => $data['city_id'] ?? null,
                'latitude'          => $data['latitude'] ?? null,
                'longitude'         => $data['longitude'] ?? null,
                'is_approved'       => false,
                'is_active'         => true,
                'is_phone_verified' => false,
                'show_social_links' => true,
                // Retail may register alone; wholesalers link later (many-to-many).
                'is_independent'    => $role === 'retail_trader',
                'parent_distributor_id' => null,
                'otp_code'          => $localOtp,
                'otp_expires_at'    => $isLibya ? null : now()->addMinutes(5),
            ]);

            if ($request->hasFile('profile_photo')) {
                $user->profile_photo = $request->file('profile_photo')->store('profile_photos', 'public');
            }

            if ($request->hasFile('brand_logo')) {
                $user->brand_logo = $request->file('brand_logo')->store('brand_logos', 'public');
            }

            $user->save();

            foreach ($data['social_links'] ?? [] as $row) {
                $user->socialLinks()->updateOrCreate(
                    ['platform' => $row['platform']],
                    ['url' => $row['url'], 'sort_order' => $row['sort_order'] ?? 0],
                );
            }

            return $user;
        });

        if ($isLibya) {
            $otpResp = $this->initiateMarsolOtp(
                $phone, 6, 300, 'WEB',
                app()->getLocale() === 'ar' ? 'AR' : 'EN',
                'CODE'
            );

            if ($otpResp && ! empty($otpResp['requestId'])) {
                $exp = max(60, min($otpResp['expiration'] ?? 300, 86400));
                $user->update([
                    'marsol_otp_request_id'   => $otpResp['requestId'],
                    'marsol_otp_resend_token' => $otpResp['resendToken'] ?? null,
                    'marsol_otp_expires_at'   => now()->addSeconds($exp),
                    'otp_last_sent_at'        => now(),
                ]);
            }
        } else {
            $this->sendMarsolSmsOtp($phone, (string) $localOtp, 5);
            $user->update(['otp_last_sent_at' => now()]);
        }

        $user->load(['city', 'country', 'storeMedia.product', 'socialLinks']);

        $this->notifyAdminsOfPendingStore($user);

        $roleLabel = $role === 'retail_trader' ? 'تاجر التجزئة' : 'موزّع الجملة';

        return response()->json([
            'status'  => true,
            'message' => "تم تسجيل {$roleLabel}. تم إرسال رمز التحقق، وحسابك بانتظار موافقة الإدارة.",
            'store_status' => [
                'code' => 'pending_approval',
                'is_approved' => false,
                'is_active' => true,
                'is_public' => false,
                'notice' => 'متجرك لم يُفعَّل بعد — طلبك قيد مراجعة الإدارة.',
            ],
            'data'    => [
                'token' => $user->createToken('auth_token')->plainTextToken,
                'store' => new NetworkStoreResource($user),
            ],
        ], 201);
    }

    protected function notifyAdminsOfPendingStore(User $store): void
    {
        $roleLabel = $store->isRetailTrader() ? 'تاجر تجزئة' : 'موزّع جملة';
        $title = "طلب تفعيل {$roleLabel} جديد 🛎️";
        $body = "«{$store->name}»".($store->brand_name ? " ({$store->brand_name})" : '')
            ." سجّل عبر التطبيق وبانتظار موافقتك — الهاتف: {$store->phone}";

        $adminPath = $store->isRetailTrader()
            ? 'retail-traders/'.$store->id
            : 'stores/'.$store->id;
        $url = AdminPanelPath::url($adminPath);

        AdminNotificationService::sendToRole('super_admin', $title, $body, 'warning', $url, 'مراجعة الطلب');
        AdminNotificationService::sendToRole('admin', $title, $body, 'warning', $url, 'مراجعة الطلب');
    }
}
