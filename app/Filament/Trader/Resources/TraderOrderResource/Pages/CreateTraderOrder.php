<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Trader\Resources\TraderOrderResource;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateTraderOrder extends CreateRecord
{
    protected static string $resource = TraderOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب جديد من موزّع الجملة';
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }

    protected function handleRecordCreation(array $data): Model
    {
        $lines = collect($data['items'] ?? [])
            ->map(fn ($item) => [
                'product_id' => (int) ($item['product_id'] ?? 0),
                'quantity' => (int) ($item['quantity'] ?? 0),
            ])
            ->all();

        try {
            return app(OrderService::class)->place(
                requester: auth()->user(),
                channel: OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL,
                lines: $lines,
                meta: ['note' => $data['note'] ?? null],
            );
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إنشاء الطلب')->body($e->getMessage())->send();

            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
    }
}
