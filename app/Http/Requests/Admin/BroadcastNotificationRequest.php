<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BroadcastNotificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && in_array($user->role, ['super_admin', 'admin'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:191'],
            'body' => ['nullable', 'string', 'max:5000'],
            'status' => ['nullable', 'string', Rule::in(['info', 'success', 'warning', 'danger'])],
            'audience' => ['nullable', 'string', Rule::in([
                'all', 'custom', 'role', 'plumbers', 'retailers', 'wholesalers', 'user', 'users',
            ])],
            'role' => ['nullable', 'string', 'max:64'],
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'city' => ['nullable', 'integer', 'exists:cities,id'],
            'points_min' => ['nullable', 'integer', 'min:0'],
            'points_max' => ['nullable', 'integer', 'min:0'],
            'last_active_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'push_type' => ['nullable', 'string', 'max:64'],
            'push_id' => ['nullable', 'string', 'max:64'],
            'send_push' => ['sometimes', 'boolean'],
            'url' => ['nullable', 'string', 'max:500'],
            'action_label' => ['nullable', 'string', 'max:64'],
            'schedule_at' => ['nullable', 'date'],
            'preview_only' => ['sometimes', 'boolean'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
