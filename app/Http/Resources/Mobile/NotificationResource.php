<?php

namespace App\Http\Resources\Mobile;

use App\Services\PendingReceiptsService;
use App\Support\DeepLink;
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
        $screen = $this->resolveScreen($data);
        $deepLink = $this->resolveDeepLink($data, $screen);

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
            'entity_type' => $data['entity_type'] ?? null,
            'order_id' => isset($data['order_id']) ? (int) $data['order_id'] : null,
            'order_number' => $data['order_number'] ?? null,
            'channel' => $data['channel'] ?? null,
            'order_status' => $data['order_status'] ?? null,
            'screen' => $screen,
            'deep_link' => $deepLink,
            'is_read' => $isRead,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'created_at_human' => $this->created_at?->diffForHumans(),
            'actions' => $actions,
            'data' => $data,
        ];
    }

    /** @param  array<string, mixed>  $data */
    protected function resolveScreen(array $data): string
    {
        $screen = strtolower(trim((string) ($data['screen'] ?? '')));
        if ($screen !== '') {
            return $screen;
        }

        $type = strtolower(trim((string) ($data['type'] ?? $data['entity_type'] ?? '')));

        return match ($type) {
            'order', 'orders' => 'order',
            'invoice', 'invoices' => 'invoice',
            'points' => 'points',
            'withdrawal', 'withdrawals' => 'withdrawal',
            'supply', 'supplies' => 'supply',
            'pending-receipts', 'pending_receipts' => PendingReceiptsService::SCREEN,
            default => 'home',
        };
    }

    /** @param  array<string, mixed>  $data */
    protected function resolveDeepLink(array $data, string $screen): string
    {
        if (filled($data['deep_link'] ?? null)) {
            return (string) $data['deep_link'];
        }

        if ($screen === PendingReceiptsService::SCREEN || $screen === 'pending_receipts') {
            $id = isset($data['entity_id']) ? (int) $data['entity_id'] : null;

            return app(PendingReceiptsService::class)->deepLink($id && $id > 0 ? $id : null);
        }

        $id = $data['entity_id'] ?? $data['order_id'] ?? $data['invoice_id'] ?? $data['id'] ?? null;
        $type = match ($screen) {
            'order' => 'order',
            'invoice' => 'invoice',
            'points' => 'points',
            'withdrawal' => 'withdrawal',
            'supply' => 'supply',
            'return' => 'return',
            default => $screen,
        };

        if (DeepLink::isAllowedType($type)) {
            return DeepLink::customScheme($type, $id);
        }

        return DeepLink::customScheme('home');
    }
}
