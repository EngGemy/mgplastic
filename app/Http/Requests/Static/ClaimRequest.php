<?php

namespace App\Http\Requests\Static;

use Illuminate\Foundation\Http\FormRequest;

class ClaimRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'    => 'required|string|max:255',
            'email'   => 'nullable|email|max:255',
            'phone'   => 'nullable|string|max:20',
            'message' => 'required|string|min:10',
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
