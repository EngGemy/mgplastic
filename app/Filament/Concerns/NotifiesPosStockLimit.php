<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;

trait NotifiesPosStockLimit
{
    protected function notifyStockLimit(string $productName, int $maxQty): void
    {
        Notification::make()
            ->warning()
            ->title('حد المخزون لديك')
            ->body("«{$productName}» — المخزون المتاح {$maxQty} وحدة فقط. لا يمكنك إضافة كمية أكثر من ذلك.")
            ->send();
    }

    protected function resolveAvailableQty(int $productId, ?int $fallback = null): int
    {
        $row = $this->stockRows()->firstWhere('product_id', $productId);

        return (int) ($row['available_qty'] ?? $fallback ?? 0);
    }
}
