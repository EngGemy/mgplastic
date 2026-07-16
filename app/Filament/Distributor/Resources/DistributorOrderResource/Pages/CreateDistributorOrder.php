<?php

namespace App\Filament\Distributor\Resources\DistributorOrderResource\Pages;

use App\Filament\Concerns\PlacesNetworkOrder;
use App\Filament\Distributor\Resources\DistributorOrderResource;
use App\Support\OrderStatus;
use Filament\Resources\Pages\Page;

class CreateDistributorOrder extends Page
{
    use PlacesNetworkOrder;

    protected static string $resource = DistributorOrderResource::class;

    protected static string $view = 'filament.orders.place-order';

    public function mount(): void
    {
        $this->prefillFromRequest();
    }

    public function getTitle(): string
    {
        return 'طلب جديد من المصنع';
    }

    public function getHeading(): string
    {
        return 'اختر المنتجات وأرسل طلبك للمصنع';
    }

    protected function orderChannel(): string
    {
        return OrderStatus::CHANNEL_FACTORY_TO_WHOLESALE;
    }

    protected function successRedirectUrl(): string
    {
        return DistributorOrderResource::getUrl('index');
    }

    protected function emptyCartMessage(): string
    {
        return 'أضف منتجاً واحداً على الأقل للطلب';
    }
}
