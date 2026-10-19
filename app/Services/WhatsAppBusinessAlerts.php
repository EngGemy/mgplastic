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
use Illuminate\Support\Facades\URL;

/**
 * Meta-style WhatsApp alerts (caption + optional brand JPEG) via WhatsAppNotifier.
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
            ->header(WhatsAppMessage::ICON_WELCOME)
            ->greet($user->brand_name ?: $user->name)
            ->event(WhatsAppMessage::ICON_WELCOME, 'مرحبًا بك في عائلة MG')
            ->line("تم تسجيلك كـ *{$role}*.")
            ->line('تابع طلباتك وفواتيرك ونقاطك من التطبيق.');

        if (filled($user->network_code)) {
            $msg->param('رقم الشبكة', $user->network_code);
        }

        $msg->footer();

        $this->fanOut(
            array_filter([$user->phone]),
            $msg->toString(),
            'welcome',
            'مرحبًا بك'
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

            return "{$name} × *{$qty}*".($pts > 0 ? " — *{$pts}* نقطة" : '');
        })->filter()->values()->all();

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_INVOICE)
            ->greet($buyerName)
            ->event(WhatsAppMessage::ICON_INVOICE, "فاتورة جديدة ({$flow})")
            ->param('رقم الفاتورة', $number)
            ->param('من', $sellerName)
            ->param('إلى', $buyerName)
            ->param('النقاط', number_format($points))
            ->param('الإجمالي', $totalMoney)
            ->param('التاريخ', $date)
            ->items('بنود الفاتورة', $lineItems)
            ->link('عرض الفاتورة', $this->invoiceShareUrl($invoice))
            ->footer();

        $this->fanOut(
            array_filter([$buyer?->phone, $seller?->phone]),
            $msg->toString(),
            'invoice',
            'فاتورة '.$number
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

            return "{$name} × *".(int) $di->quantity.'*';
        })->all();

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_ORDER)
            ->greet($to)
            ->event(WhatsAppMessage::ICON_ORDER, "تأكيد توريد ({$tierLabel})")
            ->param('الفاتورة', $invoiceNo)
            ->param('من', $from)
            ->param('إلى', $to)
            ->param('الكمية', $qty)
            ->param('النقاط', number_format($points))
            ->items('الأصناف', $lineItems)
            ->link('عرض الفاتورة', $distribution->invoice ? $this->invoiceShareUrl($distribution->invoice) : null)
            ->footer();

        $this->fanOut(
            array_filter([$distribution->toUser?->phone, $distribution->fromUser?->phone]),
            $msg->toString(),
            'invoice',
            'تأكيد توريد'
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
            ->header(WhatsAppMessage::ICON_POINTS)
            ->greet($plumber?->name)
            ->event(WhatsAppMessage::ICON_POINTS, 'تم إضافة نقاط')
            ->param('النقاط', number_format($points))
            ->param('من', $from)
            ->param('الفاتورة', $invoiceNo)
            ->link('عرض الفاتورة', $distribution->invoice ? $this->invoiceShareUrl($distribution->invoice) : null)
            ->footer();

        $this->fanOut(
            array_filter([$plumber?->phone]),
            $msg->toString(),
            'points',
            number_format($points).' نقطة'
        );
    }

    public function plumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): void
    {
        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_POINTS)
            ->greet($plumber->name)
            ->event(WhatsAppMessage::ICON_POINTS, 'توريد بضاعة لحسابك')
            ->param('من', $retailTrader->name)
            ->param('الأصناف', $lineCount)
            ->param('النقاط', number_format($totalPoints))
            ->footer();

        $this->fanOut(
            array_filter([$plumber->phone, $retailTrader->phone]),
            $msg->toString(),
            'points',
            'توريد بضاعة'
        );
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier', 'items']);
        $customer = $order->requester;
        $supplier = $order->supplier;
        $number = $order->order_number ?: '#'.$order->id;

        $lineItems = $order->items->map(function ($item) {
            $name = $item->name_snapshot
                ?: ($item->product ? localized_name($item->product, 'name', 'صنف') : 'صنف');

            return "{$name} × *".(int) $item->quantity.'*';
        })->all();

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_ORDER)
            ->greet($customer?->name)
            ->event(WhatsAppMessage::ICON_ORDER, 'طلبية جديدة')
            ->param('رقم الطلب', $number)
            ->param('الحالة', OrderStatus::label($order->status))
            ->param('الإجمالي', (int) $order->total_points.' نقطة')
            ->param('العميل', $customer?->name ?: '—');

        if ($supplier?->name) {
            $msg->param('المورّد', $supplier->name);
        }

        $msg->items('الأصناف', $lineItems)
            ->link('تفاصيل الطلب', $this->orderShareUrl($order))
            ->footer();

        $this->fanOut(
            array_filter([$customer?->phone, $supplier?->phone]),
            $msg->toString(),
            'order',
            'طلب '.$number
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $number = $order->order_number ?: '#'.$order->id;

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_STATUS)
            ->greet($order->requester?->name)
            ->event(WhatsAppMessage::ICON_STATUS, 'تحديث حالة الطلب')
            ->param('رقم الطلب', $number)
            ->param('من', OrderStatus::label($from))
            ->param('إلى', OrderStatus::label($to))
            ->link('تفاصيل الطلب', $this->orderShareUrl($order))
            ->footer();

        $this->fanOut(
            array_filter([$order->requester?->phone, $order->supplier?->phone]),
            $msg->toString(),
            'status',
            OrderStatus::label($to)
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

            return "{$name} × *".(int) $item->quantity.'*';
        })->all();

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_RETURN)
            ->greet($return->fromUser?->name)
            ->event(WhatsAppMessage::ICON_RETURN, 'طلب مرتجع جديد')
            ->param('رقم المرتجع', $number)
            ->param('الفاتورة', $invoiceRef)
            ->param('القيمة', (int) $return->total_points.' نقطة / '.(int) $return->total_quantity.' وحدة')
            ->param('السبب', trim((string) ($return->note ?: '—')))
            ->items('بنود المرتجع', $lineItems)
            ->link('عرض الفاتورة', $return->invoice ? $this->invoiceShareUrl($return->invoice) : null)
            ->footer();

        $this->fanOut(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $msg->toString(),
            'return',
            'طلب مرتجع'
        );
    }

    public function returnStatusChanged(InvoiceReturn $return, string $from, string $to): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice']);
        $number = $return->return_number ?: '#'.$return->id;

        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_RETURN)
            ->greet($return->fromUser?->name)
            ->event(WhatsAppMessage::ICON_RETURN, 'تحديث حالة المرتجع')
            ->param('رقم المرتجع', $number)
            ->param('من', InvoiceReturnStatus::label($from))
            ->param('إلى', InvoiceReturnStatus::label($to))
            ->link('عرض الفاتورة', $return->invoice ? $this->invoiceShareUrl($return->invoice) : null)
            ->footer();

        $this->fanOut(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $msg->toString(),
            'return',
            InvoiceReturnStatus::label($to)
        );
    }

    public function withdrawalCreated(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['plumber']);
        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_WITHDRAWAL)
            ->event(WhatsAppMessage::ICON_WITHDRAWAL, 'طلب سحب جديد')
            ->param('السباك', $withdrawal->plumber?->name ?: '—')
            ->param('المبلغ', $withdrawal->formattedAmount())
            ->param('الحالة', $withdrawal->statusLabel())
            ->footer();

        $this->whatsapp->notifyAdmins(
            $msg->toString(),
            $this->brandCard->assetFor('withdrawal', 'طلب سحب')
        );
    }

    public function withdrawalStatusChanged(WithdrawalRequest $withdrawal, string $from, string $to): void
    {
        $withdrawal->loadMissing(['plumber']);
        $msg = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_WITHDRAWAL)
            ->greet($withdrawal->plumber?->name)
            ->event(WhatsAppMessage::ICON_WITHDRAWAL, 'تحديث طلب السحب')
            ->param('المبلغ', $withdrawal->formattedAmount())
            ->param('من', $from)
            ->param('إلى', $withdrawal->statusLabel())
            ->footer();

        $this->fanOut(
            array_filter([$withdrawal->plumber?->phone]),
            $msg->toString(),
            'withdrawal',
            $withdrawal->statusLabel()
        );
    }

    /**
     * @param  list<string|null>  $phones
     */
    protected function fanOut(array $phones, string $caption, string $scene, string $subtitle): void
    {
        $this->whatsapp->notifyUsersAndAdmins(
            $phones,
            $caption,
            $this->brandCard->assetFor($scene, $subtitle)
        );
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
}
