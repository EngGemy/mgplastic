<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\OrderStatus;
use App\Support\PriceListWhatsApp;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class CustomerWhatsAppNotifier
{
    public function __construct(
        protected WhatsAppSender $sender,
    ) {}

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifyOrderPlaced(Order $order): array
    {
        $order->loadMissing(['requester', 'supplier']);
        $customer = $order->requester;
        if (! $customer) {
            return $this->empty('لا يوجد طالب للطلب');
        }

        return $this->deliver(
            $customer->phone,
            $this->orderPlacedMessage($order, $customer),
            'order.placed',
            $order->id,
        );
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifyOrderStatus(Order $order, string $headline): array
    {
        $order->loadMissing(['requester']);
        $customer = $order->requester;
        if (! $customer) {
            return $this->empty('لا يوجد طالب للطلب');
        }

        return $this->deliver(
            $customer->phone,
            $this->orderStatusMessage($order, $customer, $headline),
            'order.status',
            $order->id,
        );
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifyInvoiceIssued(Invoice $invoice): array
    {
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber']);
        $customer = $this->invoiceCustomer($invoice);
        if (! $customer) {
            return $this->empty('لا يوجد مستلم للفاتورة');
        }

        return $this->deliver(
            $customer->phone,
            $this->invoiceIssuedMessage($invoice, $customer),
            'invoice.issued',
            $invoice->id,
        );
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifyPlumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): array
    {
        return $this->deliver(
            $plumber->phone,
            $this->plumberSaleMessage($plumber, $retailTrader, $totalPoints, $lineCount),
            'plumber.sale',
            $plumber->id,
        );
    }

    public function orderChatUrl(Order $order): ?string
    {
        $order->loadMissing(['requester', 'supplier']);
        $customer = $order->requester;
        if (! $customer?->phone) {
            return null;
        }

        return PriceListWhatsApp::url(
            $customer->phone,
            $this->orderPlacedMessage($order, $customer),
        );
    }

    public function invoiceChatUrl(Invoice $invoice): ?string
    {
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber']);
        $customer = $this->invoiceCustomer($invoice);
        if (! $customer?->phone) {
            return null;
        }

        return PriceListWhatsApp::url(
            $customer->phone,
            $this->invoiceIssuedMessage($invoice, $customer),
        );
    }

    public function plumberSaleChatUrl(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): ?string
    {
        if (! $plumber->phone) {
            return null;
        }

        return PriceListWhatsApp::url(
            $plumber->phone,
            $this->plumberSaleMessage($plumber, $retailTrader, $totalPoints, $lineCount),
        );
    }

    /**
     * Free welcome message for any network customer (opens wa.me for staff to send).
     */
    public function welcomeChatUrl(User $customer): ?string
    {
        if (! filled(trim((string) $customer->phone))) {
            return null;
        }

        return PriceListWhatsApp::url($customer->phone, $this->welcomeMessage($customer));
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifyWelcome(User $customer): array
    {
        return $this->deliver(
            $customer->phone,
            $this->welcomeMessage($customer),
            'customer.welcome',
            $customer->id,
        );
    }

    /**
     * Remind the supplier (trader / wholesaler) that a new order arrived.
     *
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    public function notifySupplierOrderArrived(Order $order): array
    {
        $order->loadMissing(['requester', 'supplier']);
        $supplier = $order->supplier;
        if (! $supplier) {
            return $this->empty('لا يوجد مورّد للطلب');
        }

        return $this->deliver(
            $supplier->phone,
            $this->supplierOrderArrivedMessage($order, $supplier),
            'order.supplier',
            $order->id,
        );
    }

    public function supplierOrderChatUrl(Order $order): ?string
    {
        $order->loadMissing(['requester', 'supplier']);
        $supplier = $order->supplier;
        if (! $supplier?->phone) {
            return null;
        }

        return PriceListWhatsApp::url(
            $supplier->phone,
            $this->supplierOrderArrivedMessage($order, $supplier),
        );
    }

    protected function welcomeMessage(User $customer): string
    {
        $site = WebsiteSetting::instance()->site_name ?: 'MG Plastic';
        $role = match ($customer->role) {
            'wholesale_distributor' => 'موزّع جملة معتمد',
            'retail_trader' => 'تاجر قطاعي معتمد',
            'plumber' => 'سباك مسجّل',
            default => 'عميل',
        };

        $lines = [
            $this->welcomeLine($customer),
            '',
            "سعداء بانضمامك معنا كـ{$role}.",
        ];

        if (filled($customer->network_code)) {
            $lines[] = 'رقم شبكتك: '.$customer->network_code;
        }

        $lines[] = '';
        $lines[] = "من تطبيق {$site} تقدر تتابع طلباتك وفواتيرك ونقاطك في أي وقت.";
        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    protected function supplierOrderArrivedMessage(Order $order, User $supplier): string
    {
        $lines = [
            $this->welcomeLine($supplier),
            '',
            'وصلك طلب جديد 🛎️',
            'رقم الطلب: '.($order->order_number ?: '#'.$order->id),
            'من: '.($order->requester?->name ?: 'عميل'),
            'الكمية: '.(int) $order->total_quantity.' وحدة',
            'النقاط: '.(int) $order->total_points,
        ];

        if ($share = $this->orderShareUrl($order)) {
            $lines[] = '';
            $lines[] = 'راجع الطلب من الرابط:';
            $lines[] = $share;
        }

        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    public function orderShareUrl(Order $order): ?string
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

    public function invoiceShareUrl(Invoice $invoice): ?string
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

    protected function orderPlacedMessage(Order $order, User $customer): string
    {
        $lines = [
            $this->welcomeLine($customer),
            '',
            'تم استلام طلبك بنجاح ✓',
            'رقم الطلب: '.($order->order_number ?: '#'.$order->id),
            'الحالة: '.OrderStatus::label($order->status),
            'الكمية: '.(int) $order->total_quantity.' وحدة',
            'النقاط: '.(int) $order->total_points,
        ];

        if ($order->supplier?->name) {
            $lines[] = 'المورّد: '.$order->supplier->name;
        }

        if ($share = $this->orderShareUrl($order)) {
            $lines[] = '';
            $lines[] = 'راجع تفاصيل طلبك من الرابط:';
            $lines[] = $share;
        }

        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    protected function orderStatusMessage(Order $order, User $customer, string $headline): string
    {
        $lines = [
            $this->welcomeLine($customer),
            '',
            $headline,
            'رقم الطلب: '.($order->order_number ?: '#'.$order->id),
            'الحالة: '.OrderStatus::label($order->status),
        ];

        if ($share = $this->orderShareUrl($order)) {
            $lines[] = '';
            $lines[] = 'التفاصيل:';
            $lines[] = $share;
        }

        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    protected function invoiceIssuedMessage(Invoice $invoice, User $customer): string
    {
        $kind = $invoice->isOutgoing() ? 'فاتورة صادرة لحسابك' : 'فاتورة واردة من المصنع';
        $lines = [
            $this->welcomeLine($customer),
            '',
            'تم إصدار '.$kind.' ✓',
            'رقم الفاتورة: '.($invoice->number ?: '#'.$invoice->id),
            'النقاط: '.number_format((int) $invoice->documentPoints()),
        ];

        if ($share = $this->invoiceShareUrl($invoice)) {
            $lines[] = '';
            $lines[] = 'راجع الفاتورة من الرابط:';
            $lines[] = $share;
        }

        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    protected function plumberSaleMessage(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): string
    {
        $lines = [
            $this->welcomeLine($plumber),
            '',
            'تم تسجيل توريد بضاعة لحسابك ✓',
            'من: '.$retailTrader->name,
            'عدد الأصناف: '.$lineCount,
            'النقاط: '.number_format($totalPoints),
            '',
            'افتح تطبيق MG Plastic لمتابعة رصيدك وتفاصيل التوريد.',
        ];

        $this->appendSupport($lines);

        return implode("\n", $lines);
    }

    protected function invoiceCustomer(Invoice $invoice): ?User
    {
        if ($invoice->isOutgoing()) {
            return $invoice->counterparty;
        }

        return $invoice->wholesaleDistributor ?: $invoice->plumber;
    }

    protected function welcomeLine(User $customer): string
    {
        $name = trim((string) $customer->name);
        $site = WebsiteSetting::instance()->site_name ?: 'MG Plastic';

        if ($name !== '') {
            return "أهلاً {$name} 👋\nمرحباً بك في شبكة {$site}";
        }

        return "مرحباً بك في شبكة {$site} 👋";
    }

    /** @param  list<string>  $lines */
    protected function appendSupport(array &$lines): void
    {
        $settings = WebsiteSetting::instance();
        $support = trim((string) ($settings->contact_whatsapp ?: $settings->contact_phone));
        if ($support === '') {
            return;
        }

        $lines[] = '';
        $lines[] = 'للاستفسار واتساب: '.$support;
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    protected function deliver(?string $phone, string $message, string $context, int $id): array
    {
        $result = $this->sender->send($phone, $message);

        Log::info('[WhatsApp] customer notify', [
            'context' => $context,
            'id' => $id,
            'sent' => $result['sent'],
            'driver' => $result['driver'],
            'phone' => $result['phone'],
            'error' => $result['error'],
        ]);

        return $result;
    }

    /**
     * @return array{sent: bool, phone: ?string, chat_url: ?string, driver: string, error: ?string}
     */
    protected function empty(string $error): array
    {
        return [
            'sent' => false,
            'phone' => null,
            'chat_url' => null,
            'driver' => (string) config('services.whatsapp.driver', 'log'),
            'error' => $error,
        ];
    }
}
