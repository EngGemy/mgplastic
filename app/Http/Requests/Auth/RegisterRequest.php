<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class RegisterRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'        => 'nullable|string|max:255',
            'email'       => 'nullable|email|unique:users,email',
            'phone'       => 'required|string|unique:users,phone',
            'password'    => 'required|string|min:6',
            'role'        => 'nullable|in:admin,customer,plumber',
            'profile_photo' => 'nullable|string',

            // NEW:
            'country_id'  => 'nullable|integer|exists:countries,id',
            'city_id'     => 'nullable|integer|exists:cities,id',
        ];
    }

    public function withValidator($validator)
    {
        $validator->after(function ($v) {
            $countryId = $this->input('country_id');
            $cityId    = $this->input('city_id');

            // If one is present, both are required and must belong together
            if ($countryId || $cityId) {
                if (!$countryId || !$cityId) {
                    $v->errors()->add('city_id', 'Both country_id and city_id are required together.');
                    return;
                }
                if (!\App\Models\City::where('id', $cityId)->where('country_id', $countryId)->exists()) {
                    $v->errors()->add('city_id', 'City does not belong to the selected country.');
                }
            }
        });
    }

    public function authorize(): bool
    {
        return true;
    }
}
