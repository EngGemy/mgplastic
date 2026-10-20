<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\InvoiceReturn;
use App\Models\Order;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Support\DeepLink;
use App\Support\InvoiceReturnStatus;
use App\Support\Money;
use App\Support\OrderStatus;
use App\Support\WhatsAppMessage;

/**
 * Short Meta captions (customer vs admin) + one brand card per event.
 */
class WhatsAppBusinessAlerts
{
    public function __construct(
        protected WhatsAppNotifier $whatsapp,
        protected WhatsAppBrandCard $brandCard,
    ) {}

    public function welcome(User $user): void
    {
        $name = $user->brand_name ?: $user->name;
        $deep = DeepLink::for('home', 0);
        $asset = $this->brandCard->assetFor('welcome', [
            'title' => 'مرحبًا بك',
            'hero' => $user->network_code ?: 'MG',
            'hero_label' => 'رقم الشبكة',
            'client' => $name,
        ]);

        $this->dispatch(
            customers: [['phone' => $user->phone, 'name' => $name]],
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_WELCOME,
                $n,
                'مرحبًا بك في عائلة MG',
                ['تابع طلباتك ونقاطك من التطبيق'],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_WELCOME,
                null,
                'عميل جديد',
                ["الاسم: *{$name}*", 'مرجع: *تسجيل*'],
                $deep
            ),
            theme: 'welcome',
            asset: $asset,
            event: 'welcome',
            referenceType: 'user',
            referenceId: $user->id,
        );
    }

    public function invoiceCreated(Invoice $invoice): void
    {
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber', 'issuer']);
        $buyer = $this->invoiceBuyer($invoice);
        $seller = $this->invoiceSeller($invoice);
        $buyerName = $buyer?->brand_name ?: ($buyer?->name ?: '—');
        $sellerName = $seller?->brand_name ?: ($seller?->name ?: '—');
        $number = $invoice->number ?: ($invoice->serial_number ?: '#'.$invoice->id);
        $total = Money::fromCents((int) $invoice->total_cents);
        $deep = DeepLink::for('invoice', $invoice->id);

        $asset = $this->brandCard->assetFor('invoice_created', [
            'title' => 'فاتورة جديدة',
            'hero' => $total,
            'hero_label' => 'الإجمالي',
            'client' => $buyerName,
            'rows' => [['رقم', $number], ['من', $sellerName]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $buyer ? ['phone' => $buyer->phone, 'name' => $buyerName] : null,
                $seller ? ['phone' => $seller->phone, 'name' => $sellerName] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_INVOICE,
                $n,
                'فاتورة جديدة',
                ["رقم: *{$number}*", "الإجمالي: *{$total}*", '_افتح التفاصيل في التطبيق_'],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_INVOICE,
                null,
                'فاتورة جديدة',
                ["*{$sellerName}* ← *{$buyerName}*", "{$number} · *{$total}*"],
                $deep
            ),
            theme: 'invoice_created',
            asset: $asset,
            event: 'invoice_created',
            referenceType: 'invoice',
            referenceId: $invoice->id,
        );
    }

    public function distributionConfirmed(InvoiceDistribution $distribution): void
    {
        $distribution->loadMissing(['fromUser', 'toUser', 'invoice']);
        if ((int) $distribution->tier === 2) {
            return;
        }
        $from = $distribution->fromUser?->name ?: '—';
        $to = $distribution->toUser?->name ?: '—';
        $qty = (int) $distribution->items->sum('quantity');
        $invoiceNo = $distribution->invoice?->number ?: '#'.($distribution->invoice_id ?: '');
        $deep = DeepLink::for('supply', $distribution->id);

        $asset = $this->brandCard->assetFor('supply_confirmed', [
            'title' => 'تم التوريد',
            'hero' => (string) $qty,
            'hero_label' => 'كمية',
            'client' => $to,
            'rows' => [['فاتورة', $invoiceNo], ['من', $from]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $distribution->toUser ? ['phone' => $distribution->toUser->phone, 'name' => $to] : null,
                $distribution->fromUser ? ['phone' => $distribution->fromUser->phone, 'name' => $from] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_SUPPLY,
                $n,
                'تم تأكيد التوريد',
                ["فاتورة: *{$invoiceNo}*", "الكمية: *{$qty}*"],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_SUPPLY,
                null,
                'تأكيد توريد',
                ["*{$from}* ← *{$to}*", "{$invoiceNo} · *{$qty}* وحدة"],
                $deep
            ),
            theme: 'supply_confirmed',
            asset: $asset,
            event: 'supply_confirmed',
            referenceType: 'invoice_distribution',
            referenceId: $distribution->id,
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
        $pts = Money::points($points);
        $deep = DeepLink::for('points', $distribution->id);

        $asset = $this->brandCard->assetFor('points_awarded', [
            'title' => 'نقاط جديدة',
            'hero' => '+'.number_format($points),
            'hero_label' => 'نقطة',
            'client' => $plumber?->name,
            'rows' => [['من', $from]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $plumber ? ['phone' => $plumber->phone, 'name' => $plumber->name] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_POINTS,
                $n,
                'تم إضافة نقاط',
                ["النقاط: *{$pts}*", "من: *{$from}*"],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_POINTS,
                null,
                'منح نقاط',
                ['إلى: *'.($plumber?->name ?: '—').'*', "*{$pts}*"],
                $deep
            ),
            theme: 'points_awarded',
            asset: $asset,
            event: 'points_awarded',
            referenceType: 'invoice_distribution',
            referenceId: $distribution->id,
        );
    }

    public function plumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): void
    {
        $pts = Money::points($totalPoints);
        $deep = DeepLink::for('points', $plumber->id);
        $asset = $this->brandCard->assetFor('points_awarded', [
            'title' => 'توريد بضاعة',
            'hero' => '+'.number_format($totalPoints),
            'hero_label' => 'نقطة',
            'client' => $plumber->name,
            'rows' => [['من', $retailTrader->name], ['أصناف', (string) $lineCount]],
        ]);

        $this->dispatch(
            customers: [
                ['phone' => $plumber->phone, 'name' => $plumber->name],
                ['phone' => $retailTrader->phone, 'name' => $retailTrader->name],
            ],
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_POINTS,
                $n,
                'توريد بضاعة',
                ["من: *{$retailTrader->name}*", "النقاط: *{$pts}*"],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_POINTS,
                null,
                'توريد بضاعة',
                ["*{$retailTrader->name}* ← *{$plumber->name}*", "*{$pts}*"],
                $deep
            ),
            theme: 'points_awarded',
            asset: $asset,
            event: 'plumber_sale',
            referenceType: 'user',
            referenceId: $plumber->id,
        );
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $customer = $order->requester;
        $supplier = $order->supplier;
        $number = $order->order_number ?: '#'.$order->id;
        $pts = Money::points((int) $order->total_points);
        $deep = DeepLink::for('order', $order->id);

        $asset = $this->brandCard->assetFor('order_created', [
            'title' => 'طلبية جديدة',
            'hero' => number_format((int) $order->total_points),
            'hero_label' => 'نقطة',
            'client' => $customer?->name,
            'rows' => [['رقم', $number], ['حالة', OrderStatus::label($order->status)]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $customer ? ['phone' => $customer->phone, 'name' => $customer->name] : null,
                $supplier ? ['phone' => $supplier->phone, 'name' => $supplier->name] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_ORDER,
                $n,
                'طلبية جديدة',
                ["رقم: *{$number}*", "الإجمالي: *{$pts}*", '_تابع الحالة من التطبيق_'],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_ORDER,
                null,
                'طلب جديد',
                ['*'.($customer?->name ?: '—').'* ← *'.($supplier?->name ?: '—').'*', "{$number} · *{$pts}*"],
                $deep
            ),
            theme: 'order_created',
            asset: $asset,
            event: 'order_created',
            referenceType: 'order',
            referenceId: $order->id,
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $number = $order->order_number ?: '#'.$order->id;
        $theme = $this->brandCard->themeForOrderStatus($to);
        $themeMeta = $this->brandCard->theme($theme);
        $deep = DeepLink::for('order', $order->id);
        $toLabel = OrderStatus::label($to);

        $asset = $this->brandCard->assetFor($theme, [
            'title' => $toLabel,
            'hero' => $number,
            'hero_label' => 'رقم الطلب',
            'client' => $order->requester?->name,
            'rows' => [['من', OrderStatus::label($from)], ['إلى', $toLabel]],
            'progress_index' => (int) ($themeMeta['progress_index'] ?? 0),
            'badge' => $themeMeta['badge_text'] ?? null,
        ]);

        $emoji = (string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_STATUS);

        $this->dispatch(
            customers: array_filter([
                $order->requester ? ['phone' => $order->requester->phone, 'name' => $order->requester->name] : null,
                $order->supplier ? ['phone' => $order->supplier->phone, 'name' => $order->supplier->name] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                $emoji,
                $n,
                'تحديث الطلب',
                ["رقم: *{$number}*", "الحالة: *{$toLabel}*", '_الخطوة التالية في التطبيق_'],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                $emoji,
                null,
                'تحديث طلب',
                ["{$number}: *".OrderStatus::label($from)."* → *{$toLabel}*"],
                $deep
            ),
            theme: $theme,
            asset: $asset,
            event: 'order_status_'.$to,
            referenceType: 'order',
            referenceId: $order->id,
        );
    }

    public function returnCreated(InvoiceReturn $return): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice']);
        $number = $return->return_number ?: '#'.$return->id;
        $deep = DeepLink::for('return', $return->id);
        $asset = $this->brandCard->assetFor('return_created', [
            'title' => 'مرتجع جديد',
            'hero' => (string) ((int) $return->total_quantity),
            'hero_label' => 'وحدة',
            'client' => $return->fromUser?->name,
            'rows' => [['رقم', $number], ['نقاط', (string) ((int) $return->total_points)]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $return->fromUser ? ['phone' => $return->fromUser->phone, 'name' => $return->fromUser->name] : null,
                $return->toUser ? ['phone' => $return->toUser->phone, 'name' => $return->toUser->name] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_RETURN,
                $n,
                'طلب مرتجع',
                ["رقم: *{$number}*", '_راجع التفاصيل في التطبيق_'],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_RETURN,
                null,
                'مرتجع جديد',
                ['من: *'.($return->fromUser?->name ?: '—').'*', "مرجع: *{$number}*"],
                $deep
            ),
            theme: 'return_created',
            asset: $asset,
            event: 'return_created',
            referenceType: 'invoice_return',
            referenceId: $return->id,
        );
    }

    public function returnStatusChanged(InvoiceReturn $return, string $from, string $to): void
    {
        $return->loadMissing(['fromUser', 'toUser']);
        $number = $return->return_number ?: '#'.$return->id;
        $normalized = InvoiceReturnStatus::normalize($to);
        $theme = $this->brandCard->themeForReturnStatus($normalized);
        $themeMeta = $this->brandCard->theme($theme);
        $deep = DeepLink::for('return', $return->id);
        $toLabel = InvoiceReturnStatus::label($to);
        $reason = $normalized === InvoiceReturnStatus::REJECTED
            ? trim((string) ($return->note ?: ''))
            : '';

        $asset = $this->brandCard->assetFor($theme, [
            'title' => $toLabel,
            'hero' => $normalized === InvoiceReturnStatus::REJECTED ? '✗' : '✓',
            'hero_label' => $themeMeta['badge_text'] ?? '',
            'client' => $return->fromUser?->name,
            'rows' => array_values(array_filter([
                ['رقم', $number],
                $reason !== '' ? ['سبب', mb_substr($reason, 0, 28)] : null,
            ])),
            'badge' => $themeMeta['badge_text'] ?? null,
        ]);

        $emoji = (string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_RETURN);
        $linesCustomer = ["رقم: *{$number}*", "الحالة: *{$toLabel}*"];
        if ($reason !== '') {
            $linesCustomer[] = 'السبب: *'.mb_substr($reason, 0, 40).'*';
        }

        $this->dispatch(
            customers: array_filter([
                $return->fromUser ? ['phone' => $return->fromUser->phone, 'name' => $return->fromUser->name] : null,
                $return->toUser ? ['phone' => $return->toUser->phone, 'name' => $return->toUser->name] : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption($emoji, $n, 'تحديث مرتجع', $linesCustomer, $deep),
            adminCaption: fn () => $this->caption(
                $emoji,
                null,
                'تحديث مرتجع',
                ["{$number}: *".InvoiceReturnStatus::label($from)."* → *{$toLabel}*"],
                $deep
            ),
            theme: $theme,
            asset: $asset,
            event: 'return_'.$normalized,
            referenceType: 'invoice_return',
            referenceId: $return->id,
        );
    }

    public function withdrawalCreated(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['plumber']);
        $deep = DeepLink::for('withdrawal', $withdrawal->id);
        $amount = $withdrawal->formattedAmount();
        $asset = $this->brandCard->assetFor('withdrawal_pending', [
            'title' => 'طلب سحب',
            'hero' => $amount,
            'hero_label' => 'المبلغ',
            'client' => $withdrawal->plumber?->name,
            'rows' => [['حالة', $withdrawal->statusLabel()]],
            'badge' => 'يحتاج إجراء',
        ]);

        $caption = $this->caption(
            WhatsAppMessage::ICON_WITHDRAWAL,
            null,
            'طلب سحب — يحتاج إجراء',
            [
                'سباك: *'.($withdrawal->plumber?->name ?: '—').'*',
                "المبلغ: *{$amount}*",
            ],
            $deep
        );

        $this->whatsapp->notifyAdmins($caption, $asset, [
            'event' => 'withdrawal_pending',
            'reference_type' => 'withdrawal',
            'reference_id' => $withdrawal->id,
        ]);
    }

    public function withdrawalStatusChanged(WithdrawalRequest $withdrawal, string $from, string $to): void
    {
        $withdrawal->loadMissing(['plumber']);
        $deep = DeepLink::for('withdrawal', $withdrawal->id);
        $amount = $withdrawal->formattedAmount();
        $status = $withdrawal->statusLabel();
        $asset = $this->brandCard->assetFor('withdrawal_updated', [
            'title' => 'تحديث السحب',
            'hero' => $amount,
            'hero_label' => 'المبلغ',
            'client' => $withdrawal->plumber?->name,
            'rows' => [['إلى', $status]],
        ]);

        $this->dispatch(
            customers: array_filter([
                $withdrawal->plumber
                    ? ['phone' => $withdrawal->plumber->phone, 'name' => $withdrawal->plumber->name]
                    : null,
            ]),
            customerCaption: fn (?string $n) => $this->caption(
                WhatsAppMessage::ICON_WITHDRAWAL,
                $n,
                'تحديث طلب السحب',
                ["المبلغ: *{$amount}*", "الحالة: *{$status}*"],
                $deep
            ),
            adminCaption: fn () => $this->caption(
                WhatsAppMessage::ICON_WITHDRAWAL,
                null,
                'تحديث سحب',
                ['*'.($withdrawal->plumber?->name ?: '—').'*', "{$amount} · *{$status}*"],
                $deep
            ),
            theme: 'withdrawal_updated',
            asset: $asset,
            event: 'withdrawal_updated',
            referenceType: 'withdrawal',
            referenceId: $withdrawal->id,
        );
    }

    /**
     * @param  list<array{phone?:?string,name?:?string}|null>  $customers
     * @param  callable(?string): string  $customerCaption
     * @param  callable(): string  $adminCaption
     * @param  array<string, mixed>|null  $asset
     */
    protected function dispatch(
        array $customers,
        callable $customerCaption,
        callable $adminCaption,
        string $theme,
        ?array $asset,
        string $event,
        string $referenceType,
        int|string $referenceId,
    ): void {
        $messages = [];
        $seen = [];

        if ($this->whatsapp->notifyCustomers()) {
            foreach ($customers as $c) {
                if (! is_array($c) || ! filled($c['phone'] ?? null)) {
                    continue;
                }
                $phone = (string) $c['phone'];
                $norm = $this->whatsapp->normalizePhone($phone);
                if (! $norm || isset($seen[$norm])) {
                    continue;
                }
                $seen[$norm] = true;
                $messages[] = [
                    'phone' => $phone,
                    'text' => $customerCaption($c['name'] ?? null),
                    'image' => $asset,
                    'event' => $event,
                    'recipient_type' => 'customer',
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
                ];
            }
        }

        $adminText = $adminCaption();
        foreach ($this->whatsapp->adminPhones() as $phone) {
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $messages[] = [
                'phone' => $phone,
                'text' => $adminText,
                'image' => $asset,
                'event' => $event,
                'recipient_type' => 'admin',
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ];
        }

        $this->whatsapp->sendMany($messages);
    }

    /** @param  list<string>  $bodyLines  max ~3 content lines before deep link */
    protected function caption(string $icon, ?string $name, string $title, array $bodyLines, string $deep): string
    {
        $msg = WhatsAppMessage::make()
            ->header($icon)
            ->greet($name)
            ->event($icon, $title);

        foreach (array_slice($bodyLines, 0, 3) as $line) {
            $msg->line($line);
        }

        return $msg->openInApp($deep)->footer()->toString();
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
