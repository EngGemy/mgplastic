<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Distributor\Resources\DistributorOrderResource;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class CreateDistributorOrder extends CreateRecord
{
    protected static string $resource = DistributorOrderResource::class;

    public function getTitle(): string
    {
        return 'طلب جديد من المصنع';
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
                channel: OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE,
                lines: $lines,
                meta: ['note' => $data['note'] ?? null],
            );
        } catch (\DomainException $e) {
            Notification::make()->danger()->title('تعذّر إنشاء الطلب')->body($e->getMessage())->send();

            throw ValidationException::withMessages(['items' => $e->getMessage()]);
        }
    }
}
