<?php

namespace App\Http\Requests\Mobile;

use App\Notifications\Catalog\NotificationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $types = array_column(NotificationType::cases(), 'value');

        return [
            'preferences' => ['sometimes', 'array'],
            'preferences.*.type' => ['required_with:preferences', 'string', Rule::in($types)],
            'preferences.*.push' => ['sometimes', 'boolean'],
            'preferences.*.in_app' => ['sometimes', 'boolean'],
            'preferences.*.email' => ['sometimes', 'boolean'],
            'quiet_hours' => ['sometimes', 'array'],
            'quiet_hours.quiet_from' => ['nullable', 'date_format:H:i'],
            'quiet_hours.quiet_to' => ['nullable', 'date_format:H:i'],
            'quiet_hours.timezone' => ['nullable', 'timezone'],
            'quiet_hours.locale' => ['nullable', 'string', 'max:8'],
        ];
    }
}
