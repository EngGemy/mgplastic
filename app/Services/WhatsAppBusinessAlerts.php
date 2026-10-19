<?php

namespace App\Services;

use App\Models\Invoice;
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
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber']);
        $customer = $this->invoiceCustomer($invoice);
        $customerName = $customer?->name ?: '—';
        $number = $invoice->number ?: ($invoice->serial_number ?: '#'.$invoice->id);
        $total = number_format(((int) $invoice->total_cents) / 100, 2).' د.ل';
        $date = optional($invoice->created_at)->timezone(config('app.timezone'))->format('Y-m-d H:i')
            ?: now()->format('Y-m-d H:i');

        $text = "MG للصناعات البلاستيكية\n"
            ."فاتورة جديدة\n"
            ."رقم الفاتورة: {$number}\n"
            ."الإجمالي: {$total}\n"
            ."التاريخ: {$date}\n"
            ."العميل: {$customerName}";

        $this->whatsapp->notifyUserAndAdmins($customer?->phone, $text);
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'items']);
        $customer = $order->requester;
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

        $this->whatsapp->notifyUserAndAdmins($customer?->phone, $text);
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester']);
        $number = $order->order_number ?: '#'.$order->id;
        $fromLabel = OrderStatus::label($from);
        $toLabel = OrderStatus::label($to);

        $text = "MG للصناعات البلاستيكية\n"
            ."تحديث حالة الطلب\n"
            ."رقم الطلب: {$number}\n"
            ."من: {$fromLabel}\n"
            ."إلى: {$toLabel}";

        $this->whatsapp->notifyUserAndAdmins($order->requester?->phone, $text);
    }

    public function returnCreated(InvoiceReturn $return): void
    {
        $return->loadMissing(['fromUser', 'invoice']);
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

        $this->whatsapp->notifyUserAndAdmins($return->fromUser?->phone, $text);
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

        $messages = [];
        foreach ([$return->fromUser?->phone, $return->toUser?->phone] as $phone) {
            if (filled($phone)) {
                $messages[] = ['phone' => $phone, 'text' => $text];
            }
        }
        foreach ($this->whatsapp->adminPhones() as $adminPhone) {
            $messages[] = ['phone' => $adminPhone, 'text' => $text];
        }
        $this->whatsapp->sendMany($messages);
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

        // Admins only
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

    protected function invoiceCustomer(Invoice $invoice): ?User
    {
        if ($invoice->isOutgoing()) {
            return $invoice->counterparty;
        }

        return $invoice->wholesaleDistributor ?: $invoice->plumber;
    }
}
