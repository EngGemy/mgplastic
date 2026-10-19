<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Models\Order;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\InvoiceReturnStatus;
use App\Support\OrderStatus;
use App\Support\WhatsAppMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Creative Arabic WhatsApp alerts (text + optional image) via WhatsAppNotifier.
 */
class WhatsAppBusinessAlerts
{
    public function __construct(
        protected WhatsAppNotifier $whatsapp,
        protected WhatsAppBrandCard $brandCard,
    ) {}

    public function welcome(User $user): void
    {
        $role = match ($user->role) {
            'wholesale_distributor' => 'موزّع جملة معتمد',
            'retail_trader' => 'تاجر قطاعي معتمد',
            'plumber' => 'سباك مسجّل في الشبكة',
            default => 'عميل في شبكة MG',
        };

        $msg = WhatsAppMessage::make()
            ->brandHeader('🎉')
            ->greet($user->brand_name ?: $user->name)
            ->title('مرحباً بك في عائلة MG', '🌟')
            ->line('')
            ->line("سعداء بانضمامك معنا كـ *{$role}*.")
            ->line('من التطبيق تتابع طلباتك وفواتيرك ونقاطك لحظة بلحظة.');

        if (filled($user->network_code)) {
            $msg->kv('رقم شبكتك', $user->network_code);
        }

        $msg->appLinks()->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$user->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('welcome', 'مرحباً بك في عائلة MG')
        );
    }

    public function invoiceCreated(Invoice $invoice): void
    {
        $invoice->loadMissing([
            'wholesaleDistributor',
            'counterparty',
            'plumber',
            'issuer',
            'items.product.translations',
            'items.product.images',
        ]);

        $buyer = $this->invoiceBuyer($invoice);
        $seller = $this->invoiceSeller($invoice);
        $buyerName = $buyer?->brand_name ?: ($buyer?->name ?: '—');
        $sellerName = $seller?->brand_name ?: ($seller?->name ?: '—');
        $number = $invoice->number ?: ($invoice->serial_number ?: '#'.$invoice->id);
        $points = (int) $invoice->documentPoints();
        $totalMoney = number_format(((int) $invoice->total_cents) / 100, 2).' د.ل';
        $date = optional($invoice->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i')
            ?: now()->format('Y-m-d H:i');
        $flow = $invoice->isOutgoing() ? 'صادرة' : 'واردة';

        $lineItems = $invoice->items->map(function ($item) {
            $name = $item->product
                ? localized_name($item->product, 'name', 'صنف #'.$item->product_id)
                : 'صنف';
            $qty = (int) $item->quantity;
            $pts = (int) $item->total_points;

            return "{$name} × {$qty}".($pts > 0 ? " — {$pts} نقطة" : '');
        })->filter()->values()->all();

        $msg = WhatsAppMessage::make()
            ->brandHeader('🧾')
            ->greet($buyerName)
            ->title("فاتورة جديدة ({$flow})", '✅')
            ->kv('رقم الفاتورة', $number)
            ->kv('من', $sellerName)
            ->kv('إلى', $buyerName)
            ->kv('النقاط', number_format($points))
            ->kv('الإجمالي', $totalMoney)
            ->kv('التاريخ', $date)
            ->bulletList('بنود الفاتورة', $lineItems)
            ->link('عرض الفاتورة كاملة', $this->invoiceShareUrl($invoice))
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$buyer?->phone, $seller?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('invoice', 'فاتورة '.$number)
        );
    }

    public function distributionConfirmed(InvoiceDistribution $distribution): void
    {
        $distribution->loadMissing([
            'fromUser',
            'toUser',
            'items.invoiceItem.product.translations',
            'invoice',
        ]);

        if ((int) $distribution->tier === 2) {
            return;
        }

        $from = $distribution->fromUser?->name ?: '—';
        $to = $distribution->toUser?->name ?: '—';
        $points = (int) $distribution->items->sum('points_value');
        $qty = (int) $distribution->items->sum('quantity');
        $invoiceNo = $distribution->invoice?->number
            ?: ($distribution->invoice_id ? '#'.$distribution->invoice_id : '—');
        $tierLabel = match ((int) $distribution->tier) {
            1 => 'مصنع → جملة',
            3 => 'قطاعي → سباك',
            default => 'توزيع #'.$distribution->tier,
        };

        $lineItems = $distribution->items->map(function ($di) {
            $product = $di->invoiceItem?->product;
            $name = $product
                ? localized_name($product, 'name', 'صنف')
                : 'صنف';

            return "{$name} × ".(int) $di->quantity;
        })->all();

        $msg = WhatsAppMessage::make()
            ->brandHeader('🚚')
            ->greet($to)
            ->title("تأكيد توريد ({$tierLabel})", '📦')
            ->kv('الفاتورة', $invoiceNo)
            ->kv('من', $from)
            ->kv('إلى', $to)
            ->kv('الكمية', $qty)
            ->kv('النقاط', number_format($points))
            ->bulletList('الأصناف', $lineItems)
            ->link('عرض الفاتورة', $distribution->invoice ? $this->invoiceShareUrl($distribution->invoice) : null)
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$distribution->toUser?->phone, $distribution->fromUser?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('invoice', 'تأكيد توريد')
        );
    }

    public function pointsAwarded(InvoiceDistribution $distribution, int $points): void
    {
        if ($points <= 0) {
            return;
        }

        $distribution->loadMissing(['fromUser', 'toUser', 'invoice']);
        $plumber = $distribution->toUser;
        $from = $distribution->fromUser?->name ?: '—';
        $invoiceNo = $distribution->invoice?->number
            ?: ($distribution->invoice_id ? '#'.$distribution->invoice_id : '—');

        $msg = WhatsAppMessage::make()
            ->brandHeader('⭐')
            ->greet($plumber?->name)
            ->title('تم إضافة نقاط لحسابك', '🎉')
            ->kv('النقاط', number_format($points))
            ->kv('من', $from)
            ->kv('الفاتورة', $invoiceNo)
            ->line('')
            ->line('تابع رصيدك وسحوباتك من التطبيق.')
            ->link('عرض الفاتورة', $distribution->invoice ? $this->invoiceShareUrl($distribution->invoice) : null)
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$plumber?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('points', number_format($points).' نقطة')
        );
    }

    public function plumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): void
    {
        $msg = WhatsAppMessage::make()
            ->brandHeader('🛠️')
            ->greet($plumber->name)
            ->title('توريد بضاعة لحسابك', '✅')
            ->kv('من', $retailTrader->name)
            ->kv('عدد الأصناف', $lineCount)
            ->kv('النقاط', number_format($totalPoints))
            ->line('')
            ->line('افتح التطبيق لمتابعة رصيدك وتفاصيل التوريد.')
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$plumber->phone, $retailTrader->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('points', 'توريد بضاعة')
        );
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier', 'items.product.translations', 'items.product.images']);
        $customer = $order->requester;
        $supplier = $order->supplier;
        $number = $order->order_number ?: '#'.$order->id;

        $lineItems = $order->items->map(function ($item) {
            $name = $item->name_snapshot
                ?: ($item->product ? localized_name($item->product, 'name', 'صنف') : 'صنف');

            return "{$name} × ".(int) $item->quantity;
        })->all();

        $msg = WhatsAppMessage::make()
            ->brandHeader('🛒')
            ->greet($customer?->name)
            ->title('طلبية جديدة', '🆕')
            ->kv('رقم الطلب', $number)
            ->kv('الحالة', OrderStatus::label($order->status))
            ->kv('الإجمالي', (int) $order->total_points.' نقطة')
            ->kv('العميل', $customer?->name ?: '—');

        if ($supplier?->name) {
            $msg->kv('المورّد', $supplier->name);
        }

        $msg->bulletList('الأصناف', $lineItems)
            ->link('تفاصيل الطلب', $this->orderShareUrl($order))
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$customer?->phone, $supplier?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('order', 'طلب '.$number)
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester', 'supplier', 'items']);
        $number = $order->order_number ?: '#'.$order->id;

        $msg = WhatsAppMessage::make()
            ->brandHeader('🔄')
            ->greet($order->requester?->name)
            ->title('تحديث حالة الطلب', '📌')
            ->kv('رقم الطلب', $number)
            ->kv('من', OrderStatus::label($from))
            ->kv('إلى', OrderStatus::label($to))
            ->link('تفاصيل الطلب', $this->orderShareUrl($order))
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$order->requester?->phone, $order->supplier?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('status', OrderStatus::label($to))
        );
    }

    public function returnCreated(InvoiceReturn $return): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice', 'items.product.translations']);
        $number = $return->return_number ?: '#'.$return->id;
        $invoiceRef = $return->invoice?->number
            ?: ($return->invoice_id ? '#'.$return->invoice_id : '—');

        $lineItems = $return->items->map(function ($item) {
            $name = $item->product
                ? localized_name($item->product, 'name', 'صنف')
                : 'صنف';

            return "{$name} × ".(int) $item->quantity;
        })->all();

        $msg = WhatsAppMessage::make()
            ->brandHeader('↩️')
            ->greet($return->fromUser?->name)
            ->title('طلب مرتجع جديد', '⚠️')
            ->kv('رقم المرتجع', $number)
            ->kv('الفاتورة', $invoiceRef)
            ->kv('القيمة', (int) $return->total_points.' نقطة / '.(int) $return->total_quantity.' وحدة')
            ->kv('السبب', trim((string) ($return->note ?: '—')))
            ->bulletList('بنود المرتجع', $lineItems)
            ->link('عرض الفاتورة', $return->invoice ? $this->invoiceShareUrl($return->invoice) : null)
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('return', 'طلب مرتجع')
        );
    }

    public function returnStatusChanged(InvoiceReturn $return, string $from, string $to): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice']);
        $number = $return->return_number ?: '#'.$return->id;

        $msg = WhatsAppMessage::make()
            ->brandHeader('📋')
            ->greet($return->fromUser?->name)
            ->title('تحديث حالة المرتجع', '🔔')
            ->kv('رقم المرتجع', $number)
            ->kv('من', InvoiceReturnStatus::label($from))
            ->kv('إلى', InvoiceReturnStatus::label($to))
            ->link('عرض الفاتورة', $return->invoice ? $this->invoiceShareUrl($return->invoice) : null)
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $msg->toString(),
            $this->brandCard->assetFor('return', InvoiceReturnStatus::label($to))
        );
    }

    public function withdrawalCreated(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['plumber']);
        $msg = WhatsAppMessage::make()
            ->brandHeader('💰')
            ->title('طلب سحب جديد', '⏳')
            ->kv('السباك', $withdrawal->plumber?->name ?: '—')
            ->kv('المبلغ', $withdrawal->formattedAmount())
            ->kv('الحالة', $withdrawal->statusLabel())
            ->footer();

        $this->whatsapp->notifyAdmins($msg->toString());
    }

    public function withdrawalStatusChanged(WithdrawalRequest $withdrawal, string $from, string $to): void
    {
        $withdrawal->loadMissing(['plumber']);
        $msg = WhatsAppMessage::make()
            ->brandHeader('💰')
            ->greet($withdrawal->plumber?->name)
            ->title('تحديث طلب السحب', '🔔')
            ->kv('المبلغ', $withdrawal->formattedAmount())
            ->kv('من', $from)
            ->kv('إلى', $withdrawal->statusLabel())
            ->appLinks()
            ->footer();

        $this->whatsapp->notifyUserAndAdmins($withdrawal->plumber?->phone, $msg->toString());
    }

    protected function invoiceBuyer(Invoice $invoice): ?User
    {
        if ($invoice->isOutgoing()) {
            return $invoice->counterparty;
        }

        return $invoice->wholesaleDistributor ?: $invoice->plumber ?: $invoice->counterparty;
    }

    protected function invoiceSeller(Invoice $invoice): ?User
    {
        if ($invoice->isOutgoing()) {
            return $invoice->wholesaleDistributor ?: $invoice->issuer;
        }

        return $invoice->issuer;
    }

    protected function invoiceShareUrl(Invoice $invoice): ?string
    {
        try {
            return URL::temporarySignedRoute(
                'invoices.share',
                now()->addDays((int) config('services.whatsapp.share_days', 30)),
                ['invoice' => $invoice->id],
            );
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] invoice share URL failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function orderShareUrl(Order $order): ?string
    {
        try {
            return URL::temporarySignedRoute(
                'orders.share',
                now()->addDays((int) config('services.whatsapp.share_days', 30)),
                ['order' => $order->id],
            );
        } catch (\Throwable $e) {
            Log::warning('[WhatsApp] order share URL failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    protected function firstProductImageUrl(Invoice $invoice): ?string
    {
        foreach ($invoice->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $path = $product->catalog_image_path
                ?? $product->main_image
                ?? $product->images->sortBy('sort')->first()?->image;
            if ($url = $this->publicStorageUrl($path)) {
                return $url;
            }
            if (filled($product->display_image_url)) {
                return $product->display_image_url;
            }
        }

        return null;
    }

    protected function firstOrderImageUrl(Order $order): ?string
    {
        foreach ($order->items as $item) {
            $product = $item->product;
            if (! $product) {
                continue;
            }
            $path = $product->catalog_image_path
                ?? $product->main_image
                ?? $product->images->sortBy('sort')->first()?->image;
            if ($url = $this->publicStorageUrl($path)) {
                return $url;
            }
        }

        return null;
    }

    protected function brandImageUrl(): ?string
    {
        $configured = trim((string) config('services.waha.brand_image_url', ''));
        if ($configured !== '') {
            return $configured;
        }

        $path = trim((string) config('services.waha.brand_image_path', ''));

        return $this->publicStorageUrl($path !== '' ? $path : null);
    }

    protected function publicStorageUrl(?string $path): ?string
    {
        if (! filled($path)) {
            return null;
        }

        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        try {
            return Storage::disk('public')->url(ltrim($path, '/'));
        } catch (\Throwable) {
            return asset('storage/'.ltrim($path, '/'));
        }
    }
}
