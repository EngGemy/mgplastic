<?php

namespace App\Filament\Trader\Resources\TraderOrderResource\Pages;

use App\Filament\Concerns\PlacesNetworkOrder;
use App\Filament\Trader\Resources\TraderOrderResource;
use App\Support\OrderStatus;
use Filament\Resources\Pages\Page;

class CreateTraderOrder extends Page
{
    use PlacesNetworkOrder;

    protected static string $resource = TraderOrderResource::class;

    protected static string $view = 'filament.orders.place-order';

    public function getTitle(): string
    {
        return 'طلب جديد من موزّع الجملة';
    }

    public function getHeading(): string
    {
        return 'اختر المنتجات وأرسل طلبك لموزّع الجملة';
    }

    protected function orderChannel(): string
    {
        return OrderStatus::CHANNEL_WHOLESALE_TO_RETAIL;
    }

    protected function successRedirectUrl(): string
    {
        return TraderOrderResource::getUrl('index');
    }

    protected function emptyCartMessage(): string
    {
        return 'أضف منتجاً واحداً على الأقل للطلب';
    }
}
