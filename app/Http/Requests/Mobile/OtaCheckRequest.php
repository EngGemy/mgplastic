<?php

namespace App\Http\Requests\Mobile;

use App\Models\OtaUpdate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OtaCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => ['required', 'string', Rule::in([OtaUpdate::PLATFORM_IOS, OtaUpdate::PLATFORM_ANDROID])],
            'native_build' => ['required', 'string', 'max:64'],
            'bundle_version' => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'platform.required' => 'platform مطلوب',
            'platform.in' => 'platform يجب أن يكون ios أو android',
            'native_build.required' => 'native_build مطلوب',
            'bundle_version.required' => 'bundle_version مطلوب',
            'bundle_version.integer' => 'bundle_version يجب أن يكون رقماً صحيحاً',
        ];
    }
}
