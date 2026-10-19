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

/**
 * Builds Arabic WhatsApp texts and fans out via WhatsAppNotifier (queued).
 */
class WhatsAppBusinessAlerts
{
    public function __construct(
        protected WhatsAppNotifier $whatsapp,
    ) {}

    public function invoiceCreated(Invoice $invoice): void
    {
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber', 'issuer']);

        $buyer = $this->invoiceBuyer($invoice);
        $seller = $this->invoiceSeller($invoice);
        $buyerName = $buyer?->name ?: ($buyer?->brand_name ?: '—');
        $sellerName = $seller?->name ?: ($seller?->brand_name ?: '—');
        $number = $invoice->number ?: ($invoice->serial_number ?: '#'.$invoice->id);
        $points = (int) ($invoice->points_awarded ?? 0);
        if ($points <= 0) {
            $points = (int) $invoice->items()->sum('total_points');
        }
        $totalMoney = number_format(((int) $invoice->total_cents) / 100, 2).' د.ل';
        $date = optional($invoice->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i')
            ?: now()->format('Y-m-d H:i');
        $flow = $invoice->isOutgoing() ? 'صادرة' : 'واردة';

        $text = "MG للصناعات البلاستيكية\n"
            ."فاتورة جديدة ({$flow})\n"
            ."رقم الفاتورة: {$number}\n"
            ."من: {$sellerName}\n"
            ."إلى: {$buyerName}\n"
            ."النقاط: {$points}\n"
            ."الإجمالي: {$totalMoney}\n"
            ."التاريخ: {$date}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$buyer?->phone, $seller?->phone]),
            $text
        );
    }

    public function distributionConfirmed(InvoiceDistribution $distribution): void
    {
        $distribution->loadMissing(['fromUser', 'toUser', 'items', 'invoice']);

        // Tier 2 already notified via outgoing Invoice::created — skip duplicate.
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

        $text = "MG للصناعات البلاستيكية\n"
            ."تأكيد توريد ({$tierLabel})\n"
            ."الفاتورة: {$invoiceNo}\n"
            ."من: {$from}\n"
            ."إلى: {$to}\n"
            ."الكمية: {$qty}\n"
            ."النقاط: {$points}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$distribution->toUser?->phone, $distribution->fromUser?->phone]),
            $text
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

        $text = "MG للصناعات البلاستيكية\n"
            ."تم إضافة نقاط لحسابك\n"
            ."النقاط: {$points}\n"
            ."من: {$from}\n"
            ."الفاتورة: {$invoiceNo}\n"
            ."افتح التطبيق لمتابعة رصيدك.";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$plumber?->phone]),
            $text
        );
    }

    public function plumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): void
    {
        $text = "MG للصناعات البلاستيكية\n"
            ."تم تسجيل توريد بضاعة لحسابك\n"
            ."من: {$retailTrader->name}\n"
            ."عدد الأصناف: {$lineCount}\n"
            ."النقاط: {$totalPoints}\n"
            ."افتح تطبيق MG Plastic لمتابعة رصيدك.";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$plumber->phone, $retailTrader->phone]),
            $text
        );
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier', 'items']);
        $customer = $order->requester;
        $supplier = $order->supplier;
        $number = $order->order_number ?: '#'.$order->id;
        $itemsSummary = $order->items
            ->take(5)
            ->map(fn ($item) => ($item->name_snapshot ?: 'صنف').' × '.(int) $item->quantity)
            ->implode('، ');
        if ($order->items->count() > 5) {
            $itemsSummary .= '…';
        }
        if ($itemsSummary === '') {
            $itemsSummary = (int) $order->total_quantity.' وحدة';
        }
        $total = (int) $order->total_points.' نقطة';
        $customerName = $customer?->name ?: '—';

        $text = "MG للصناعات البلاستيكية\n"
            ."طلبية جديدة\n"
            ."رقم الطلب: {$number}\n"
            ."الأصناف: {$itemsSummary}\n"
            ."الإجمالي: {$total}\n"
            ."العميل: {$customerName}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$customer?->phone, $supplier?->phone]),
            $text
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $number = $order->order_number ?: '#'.$order->id;
        $fromLabel = OrderStatus::label($from);
        $toLabel = OrderStatus::label($to);

        $text = "MG للصناعات البلاستيكية\n"
            ."تحديث حالة الطلب\n"
            ."رقم الطلب: {$number}\n"
            ."من: {$fromLabel}\n"
            ."إلى: {$toLabel}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$order->requester?->phone, $order->supplier?->phone]),
            $text
        );
    }

    public function returnCreated(InvoiceReturn $return): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice']);
        $number = $return->return_number ?: '#'.$return->id;
        $invoiceRef = $return->invoice?->number
            ?: ($return->invoice_id ? '#'.$return->invoice_id : '—');
        $value = (int) $return->total_points.' نقطة / '.(int) $return->total_quantity.' وحدة';
        $reason = trim((string) ($return->note ?: '—'));

        $text = "MG للصناعات البلاستيكية\n"
            ."طلب مرتجع جديد\n"
            ."رقم المرتجع: {$number}\n"
            ."الفاتورة: {$invoiceRef}\n"
            ."القيمة: {$value}\n"
            ."السبب: {$reason}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $text
        );
    }

    public function returnStatusChanged(InvoiceReturn $return, string $from, string $to): void
    {
        $return->loadMissing(['fromUser', 'toUser']);
        $number = $return->return_number ?: '#'.$return->id;
        $fromLabel = InvoiceReturnStatus::label($from);
        $toLabel = InvoiceReturnStatus::label($to);

        $text = "MG للصناعات البلاستيكية\n"
            ."تحديث حالة المرتجع\n"
            ."رقم المرتجع: {$number}\n"
            ."من: {$fromLabel}\n"
            ."إلى: {$toLabel}";

        $this->whatsapp->notifyUsersAndAdmins(
            array_filter([$return->fromUser?->phone, $return->toUser?->phone]),
            $text
        );
    }

    public function withdrawalCreated(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['plumber']);
        $plumberName = $withdrawal->plumber?->name ?: '—';
        $amount = $withdrawal->formattedAmount();

        $text = "MG للصناعات البلاستيكية\n"
            ."طلب سحب جديد بانتظار الموافقة\n"
            ."السباك: {$plumberName}\n"
            ."المبلغ: {$amount}\n"
            ."الحالة: ".$withdrawal->statusLabel();

        $this->whatsapp->notifyAdmins($text);
    }

    public function withdrawalStatusChanged(WithdrawalRequest $withdrawal, string $from, string $to): void
    {
        $withdrawal->loadMissing(['plumber']);
        $plumberName = $withdrawal->plumber?->name ?: '—';
        $amount = $withdrawal->formattedAmount();

        $text = "MG للصناعات البلاستيكية\n"
            ."تحديث طلب السحب\n"
            ."السباك: {$plumberName}\n"
            ."المبلغ: {$amount}\n"
            ."من: {$from}\n"
            ."إلى: ".$withdrawal->statusLabel();

        $this->whatsapp->notifyUserAndAdmins($withdrawal->plumber?->phone, $text);
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
}
