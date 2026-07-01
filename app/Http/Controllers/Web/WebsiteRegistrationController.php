<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\City;
use App\Models\Country;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class WebsiteRegistrationController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'phone' => ['required', 'string', 'max:40', 'unique:users,phone'],
            'city_id' => ['required', 'exists:cities,id'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', Rule::in(['wholesale_distributor', 'retail_trader', 'plumber'])],
            'business_name' => ['nullable', 'string', 'max:255'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'يجب الموافقة على الشروط والأحكام.',
        ]);

        $countryId = Country::query()
            ->where('name_en', 'Libya')
            ->orWhere('name_ar', 'ليبيا')
            ->value('id');

        if (! $countryId) {
            return response()->json(['message' => 'تعذر تحديد الدولة الافتراضية.'], 422);
        }

        $cityValid = City::where('id', $data['city_id'])
            ->where('country_id', $countryId)
            ->exists();

        if (! $cityValid) {
            return response()->json(['message' => 'المدينة المختارة غير صالحة.'], 422);
        }

        $user = User::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'country_id' => $countryId,
            'city_id' => $data['city_id'],
            'password' => Hash::make($data['password']),
            'role' => $data['role'],
            'brand_name' => $data['business_name'] ?? null,
            'is_approved' => false,
            'is_active' => true,
            'is_phone_verified' => false,
            'is_independent' => $data['role'] !== 'plumber',
        ]);

        $panelLinks = [
            'wholesale_distributor' => '/distributor',
            'retail_trader' => '/trader',
            'plumber' => config('portal.plumber_app_url') ?: route('portal'),
        ];

        return response()->json([
            'status' => true,
            'message' => 'تم إرسال طلب التسجيل بنجاح. سيتم مراجعته خلال ٢٤–٤٨ ساعة.',
            'data' => [
                'user_id' => $user->id,
                'role' => $user->role,
                'panel_url' => $panelLinks[$user->role] ?? route('portal'),
            ],
        ], 201);
    }
}
