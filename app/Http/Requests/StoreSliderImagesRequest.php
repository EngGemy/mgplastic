<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSliderImagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Gate by policy in controller
    }

    public function rules(): array
    {
        return [
            'images'          => ['required','array','min:1'],
            'images.*'        => ['required','file','image','mimes:jpg,jpeg,png,webp','max:4096'],
            'captions'        => ['sometimes','array'],
            'captions.*'      => ['nullable','string','max:255'],
            'sort_orders'     => ['sometimes','array'],
            'sort_orders.*'   => ['nullable','integer','min:0','max:100000'],
            'set_primary_idx' => ['sometimes','nullable','integer','min:0'], // which uploaded index to set primary
        ];
    }
}
