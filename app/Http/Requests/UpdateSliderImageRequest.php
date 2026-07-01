<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSliderImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // policy in controller
    }

    public function rules(): array
    {
        return [
            'caption'    => ['sometimes','nullable','string','max:255'],
            'is_active'  => ['sometimes','boolean'],
            'is_primary' => ['sometimes','boolean'],
            'sort_order' => ['sometimes','integer','min:0','max:100000'],
        ];
    }
}
