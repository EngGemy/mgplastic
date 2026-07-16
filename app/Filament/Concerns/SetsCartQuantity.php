<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;

trait SetsCartQuantity
{
    /**
     * Set cart line quantity by typed value (manual entry).
     * Uses max_qty or available_qty when present; remove line if qty < 1.
     */
    public function setQuantity(string $key, mixed $value): void
    {
        if (! isset($this->cart[$key])) {
            return;
        }

        $qty = (int) $value;

        if ($qty < 1) {
            unset($this->cart[$key]);

            return;
        }

        $max = $this->cartLineMaxQty($key);

        if ($max !== null && $qty > $max) {
            $name = (string) ($this->cart[$key]['name'] ?? 'المنتج');
            $this->cart[$key]['quantity'] = $max;

            if (method_exists($this, 'notifyStockLimit')) {
                $this->notifyStockLimit($name, $max);
            } elseif (method_exists($this, 'notifyPosStockLimit')) {
                $this->notifyPosStockLimit($name, $max);
            } else {
                Notification::make()
                    ->warning()
                    ->title('تجاوز الحد المتاح')
                    ->body("«{$name}» — الحد الأقصى {$max} وحدة")
                    ->send();
            }

            return;
        }

        $this->cart[$key]['quantity'] = $qty;
    }

    protected function cartLineMaxQty(string $key): ?int
    {
        $line = $this->cart[$key] ?? null;
        if (! is_array($line)) {
            return null;
        }

        if (isset($line['max_qty'])) {
            return (int) $line['max_qty'];
        }

        if (isset($line['available_qty'])) {
            return (int) $line['available_qty'];
        }

        return null;
    }
}
