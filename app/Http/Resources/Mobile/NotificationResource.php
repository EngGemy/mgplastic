<?php

namespace App\Http\Resources\Mobile;

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
        $status = (string) ($data['status'] ?? 'info');
        $isRead = $this->read_at !== null;

        $actions = collect($data['actions'] ?? [])->map(function ($action) {
            if (! is_array($action)) {
                return null;
            }

            return [
                'name' => $action['name'] ?? null,
                'label' => $action['label'] ?? ($action['name'] ?? null),
                'url' => $action['url'] ?? null,
                'color' => $action['color'] ?? null,
                'event' => $action['event'] ?? null,
            ];
        })->filter()->values()->all();

        return [
            'id' => $this->id,
            'type' => class_basename((string) $this->type),
            'type_fqcn' => $this->type,
            'title' => (string) ($data['title'] ?? ''),
            'body' => (string) ($data['body'] ?? ''),
            'status' => $status,
            'status_color' => match ($status) {
                'success' => 'success',
                'warning' => 'warning',
                'danger' => 'danger',
                default => 'info',
            },
            'icon' => $data['icon'] ?? null,
            'format' => $data['format'] ?? null,
            'duration' => $data['duration'] ?? null,
            'is_read' => $isRead,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'actions' => $actions,
            'data' => $data,
        ];
    }
}
