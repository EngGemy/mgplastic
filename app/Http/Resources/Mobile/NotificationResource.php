<?php

namespace App\Http\Resources\Mobile;

use Filament\Notifications\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = (array) ($this->data ?? []);

        return [
            'id' => $this->id,
            'title' => $data['title'] ?? '',
            'body' => $data['body'] ?? '',
            'status' => $data['status'] ?? 'info',
            'icon' => $data['icon'] ?? null,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'actions' => $data['actions'] ?? [],
        ];
    }
}
