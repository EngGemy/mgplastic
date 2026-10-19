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
use App\Support\OrderStatus;
use App\Support\WhatsAppMessage;

/**
 * Themed WhatsApp alerts: one JPEG card per event + personalized Meta captions + deep links.
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

        $deep = DeepLink::for('home', 0);
        $card = [
            'title' => 'مرحبًا بك في عائلة MG',
            'hero' => $user->network_code ?: 'MG',
            'hero_label' => 'رقم الشبكة',
            'rows' => [
                ['الدور', $role],
            ],
            'footnote' => 'تابع فواتيرك ونقاطك من التطبيق',
        ];

        $this->dispatch(
            [['phone' => $user->phone, 'name' => $user->brand_name ?: $user->name]],
            function (?string $name) use ($role, $user, $deep) {
                $msg = WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_WELCOME)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_WELCOME, 'مرحبًا بك في عائلة MG')
                    ->line("تم تسجيلك كـ *{$role}*.");
                if (filled($user->network_code)) {
                    $msg->param('رقم الشبكة', $user->network_code);
                }

                return $msg->openInApp($deep)->footer()->toString();
            },
            'welcome',
            $card
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
        $deep = DeepLink::for('invoice', $invoice->id);

        $lineItems = $invoice->items->map(function ($item) {
            $name = $item->product
                ? localized_name($item->product, 'name', 'صنف #'.$item->product_id)
                : 'صنف';
            $qty = (int) $item->quantity;
            $pts = (int) $item->total_points;

            return "{$name} × *{$qty}*".($pts > 0 ? " — *{$pts}* نقطة" : '');
        })->filter()->values()->all();

        $card = [
            'title' => "فاتورة {$flow}",
            'hero' => $totalMoney,
            'hero_label' => 'الإجمالي',
            'rows' => [
                ['رقم', $number],
                ['من', $sellerName],
                ['إلى', $buyerName],
                ['نقاط', number_format($points)],
            ],
            'footnote' => $date,
        ];

        $this->dispatch(
            array_filter([
                $buyer ? ['phone' => $buyer->phone, 'name' => $buyerName] : null,
                $seller ? ['phone' => $seller->phone, 'name' => $sellerName] : null,
            ]),
            function (?string $name) use ($flow, $number, $sellerName, $buyerName, $points, $totalMoney, $date, $lineItems, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_INVOICE)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_INVOICE, "فاتورة جديدة ({$flow})")
                    ->param('رقم الفاتورة', $number)
                    ->param('من', $sellerName)
                    ->param('إلى', $buyerName)
                    ->param('النقاط', number_format($points))
                    ->param('الإجمالي', $totalMoney)
                    ->param('التاريخ', $date)
                    ->items('بنود الفاتورة', $lineItems)
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'invoice_created',
            $card
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
        $deep = DeepLink::for('supply', $distribution->id);

        $lineItems = $distribution->items->map(function ($di) {
            $product = $di->invoiceItem?->product;
            $name = $product
                ? localized_name($product, 'name', 'صنف')
                : 'صنف';

            return "{$name} × *".(int) $di->quantity.'*';
        })->all();

        $card = [
            'title' => "تأكيد توريد ({$tierLabel})",
            'hero' => (string) $qty,
            'hero_label' => 'إجمالي الكمية',
            'rows' => [
                ['فاتورة', $invoiceNo],
                ['من', $from],
                ['إلى', $to],
                ['نقاط', number_format($points)],
            ],
        ];

        $this->dispatch(
            array_filter([
                $distribution->toUser ? ['phone' => $distribution->toUser->phone, 'name' => $to] : null,
                $distribution->fromUser ? ['phone' => $distribution->fromUser->phone, 'name' => $from] : null,
            ]),
            function (?string $name) use ($tierLabel, $invoiceNo, $from, $to, $qty, $points, $lineItems, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_SUPPLY)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_SUPPLY, "تأكيد توريد ({$tierLabel})")
                    ->param('الفاتورة', $invoiceNo)
                    ->param('من', $from)
                    ->param('إلى', $to)
                    ->param('الكمية', $qty)
                    ->param('النقاط', number_format($points))
                    ->items('الأصناف', $lineItems)
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'supply_confirmed',
            $card
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
        $deep = DeepLink::for('points', $distribution->id);

        $card = [
            'title' => 'تم إضافة نقاط',
            'hero' => '+'.number_format($points),
            'hero_label' => 'نقطة',
            'rows' => [
                ['من', $from],
                ['فاتورة', $invoiceNo],
            ],
            'footnote' => 'افتح التطبيق لمشاهدة رصيدك',
        ];

        $this->dispatch(
            array_filter([
                $plumber ? ['phone' => $plumber->phone, 'name' => $plumber->name] : null,
            ]),
            function (?string $name) use ($points, $from, $invoiceNo, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_POINTS)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_POINTS, 'تم إضافة نقاط')
                    ->param('النقاط', number_format($points))
                    ->param('من', $from)
                    ->param('الفاتورة', $invoiceNo)
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'points_awarded',
            $card
        );
    }

    public function plumberSale(User $plumber, User $retailTrader, int $totalPoints, int $lineCount): void
    {
        $deep = DeepLink::for('points', $plumber->id);
        $card = [
            'title' => 'توريد بضاعة',
            'hero' => '+'.number_format($totalPoints),
            'hero_label' => 'نقطة',
            'rows' => [
                ['من', $retailTrader->name],
                ['أصناف', (string) $lineCount],
            ],
        ];

        $this->dispatch(
            array_filter([
                ['phone' => $plumber->phone, 'name' => $plumber->name],
                ['phone' => $retailTrader->phone, 'name' => $retailTrader->name],
            ]),
            function (?string $name) use ($retailTrader, $lineCount, $totalPoints, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_POINTS)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_POINTS, 'توريد بضاعة لحسابك')
                    ->param('من', $retailTrader->name)
                    ->param('الأصناف', $lineCount)
                    ->param('النقاط', number_format($totalPoints))
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'points_awarded',
            $card
        );
    }

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier', 'items']);
        $customer = $order->requester;
        $supplier = $order->supplier;
        $number = $order->order_number ?: '#'.$order->id;
        $deep = DeepLink::for('order', $order->id);

        $lineItems = $order->items->map(function ($item) {
            $name = $item->name_snapshot
                ?: ($item->product ? localized_name($item->product, 'name', 'صنف') : 'صنف');

            return "{$name} × *".(int) $item->quantity.'*';
        })->all();

        $card = [
            'title' => 'طلبية جديدة',
            'hero' => (string) ((int) $order->total_points),
            'hero_label' => 'نقطة',
            'rows' => [
                ['رقم', $number],
                ['حالة', OrderStatus::label($order->status)],
                ['عميل', $customer?->name ?: '—'],
            ],
        ];

        $this->dispatch(
            array_filter([
                $customer ? ['phone' => $customer->phone, 'name' => $customer->name] : null,
                $supplier ? ['phone' => $supplier->phone, 'name' => $supplier->name] : null,
            ]),
            function (?string $name) use ($number, $order, $customer, $supplier, $lineItems, $deep) {
                $msg = WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_ORDER)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_ORDER, 'طلبية جديدة')
                    ->param('رقم الطلب', $number)
                    ->param('الحالة', OrderStatus::label($order->status))
                    ->param('الإجمالي', (int) $order->total_points.' نقطة')
                    ->param('العميل', $customer?->name ?: '—');
                if ($supplier?->name) {
                    $msg->param('المورّد', $supplier->name);
                }

                return $msg->items('الأصناف', $lineItems)
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'order_created',
            $card
        );
    }

    public function orderStatusChanged(Order $order, string $from, string $to): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $number = $order->order_number ?: '#'.$order->id;
        $theme = $this->brandCard->themeForOrderStatus($to);
        $themeMeta = $this->brandCard->theme($theme);
        $deep = DeepLink::for('order', $order->id);

        $card = [
            'title' => OrderStatus::label($to),
            'hero' => $number,
            'hero_label' => 'رقم الطلب',
            'rows' => [
                ['من', OrderStatus::label($from)],
                ['إلى', OrderStatus::label($to)],
            ],
            'progress_index' => (int) ($themeMeta['progress_index'] ?? 0),
            'badge' => $themeMeta['badge_text'] ?? null,
        ];

        $this->dispatch(
            array_filter([
                $order->requester ? ['phone' => $order->requester->phone, 'name' => $order->requester->name] : null,
                $order->supplier ? ['phone' => $order->supplier->phone, 'name' => $order->supplier->name] : null,
            ]),
            function (?string $name) use ($number, $from, $to, $deep, $themeMeta) {
                return WhatsAppMessage::make()
                    ->header((string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_STATUS))
                    ->greet($name)
                    ->event((string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_STATUS), 'تحديث حالة الطلب')
                    ->param('رقم الطلب', $number)
                    ->param('من', OrderStatus::label($from))
                    ->param('إلى', OrderStatus::label($to))
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            $theme,
            $card
        );
    }

    public function returnCreated(InvoiceReturn $return): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice', 'items.product.translations']);
        $number = $return->return_number ?: '#'.$return->id;
        $invoiceRef = $return->invoice?->number
            ?: ($return->invoice_id ? '#'.$return->invoice_id : '—');
        $deep = DeepLink::for('return', $return->id);
        $reason = trim((string) ($return->note ?: '—'));

        $lineItems = $return->items->map(function ($item) {
            $name = $item->product
                ? localized_name($item->product, 'name', 'صنف')
                : 'صنف';

            return "{$name} × *".(int) $item->quantity.'*';
        })->all();

        $card = [
            'title' => 'طلب مرتجع',
            'hero' => (string) ((int) $return->total_quantity),
            'hero_label' => 'وحدة',
            'rows' => [
                ['رقم', $number],
                ['فاتورة', $invoiceRef],
                ['نقاط', (string) ((int) $return->total_points)],
            ],
        ];

        $this->dispatch(
            array_filter([
                $return->fromUser ? ['phone' => $return->fromUser->phone, 'name' => $return->fromUser->name] : null,
                $return->toUser ? ['phone' => $return->toUser->phone, 'name' => $return->toUser->name] : null,
            ]),
            function (?string $name) use ($number, $invoiceRef, $return, $reason, $lineItems, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_RETURN)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_RETURN, 'طلب مرتجع جديد')
                    ->param('رقم المرتجع', $number)
                    ->param('الفاتورة', $invoiceRef)
                    ->param('القيمة', (int) $return->total_points.' نقطة / '.(int) $return->total_quantity.' وحدة')
                    ->param('السبب', $reason)
                    ->items('بنود المرتجع', $lineItems)
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'return_created',
            $card
        );
    }

    public function returnStatusChanged(InvoiceReturn $return, string $from, string $to): void
    {
        $return->loadMissing(['fromUser', 'toUser', 'invoice']);
        $number = $return->return_number ?: '#'.$return->id;
        $normalized = InvoiceReturnStatus::normalize($to);
        $theme = $this->brandCard->themeForReturnStatus($normalized);
        $themeMeta = $this->brandCard->theme($theme);
        $deep = DeepLink::for('return', $return->id);
        $reason = trim((string) ($return->note ?: ''));

        $rows = [
            ['رقم', $number],
            ['من', InvoiceReturnStatus::label($from)],
            ['إلى', InvoiceReturnStatus::label($to)],
        ];
        if ($normalized === InvoiceReturnStatus::REJECTED && $reason !== '') {
            $rows[] = ['سبب', mb_substr($reason, 0, 40)];
        }

        $card = [
            'title' => InvoiceReturnStatus::label($to),
            'hero' => $normalized === InvoiceReturnStatus::REJECTED ? '✗' : '✓',
            'hero_label' => $themeMeta['badge_text'] ?? '',
            'rows' => $rows,
            'badge' => $themeMeta['badge_text'] ?? null,
        ];

        $this->dispatch(
            array_filter([
                $return->fromUser ? ['phone' => $return->fromUser->phone, 'name' => $return->fromUser->name] : null,
                $return->toUser ? ['phone' => $return->toUser->phone, 'name' => $return->toUser->name] : null,
            ]),
            function (?string $name) use ($number, $from, $to, $deep, $themeMeta, $normalized, $reason) {
                $msg = WhatsAppMessage::make()
                    ->header((string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_RETURN))
                    ->greet($name)
                    ->event((string) ($themeMeta['emoji'] ?? WhatsAppMessage::ICON_RETURN), 'تحديث حالة المرتجع')
                    ->param('رقم المرتجع', $number)
                    ->param('من', InvoiceReturnStatus::label($from))
                    ->param('إلى', InvoiceReturnStatus::label($to));
                if ($normalized === InvoiceReturnStatus::REJECTED && $reason !== '') {
                    $msg->param('السبب', $reason);
                }

                return $msg->openInApp($deep)->footer()->toString();
            },
            $theme,
            $card
        );
    }

    public function withdrawalCreated(WithdrawalRequest $withdrawal): void
    {
        $withdrawal->loadMissing(['plumber']);
        $deep = DeepLink::for('withdrawal', $withdrawal->id);
        $card = [
            'title' => 'طلب سحب جديد',
            'hero' => $withdrawal->formattedAmount(),
            'hero_label' => 'المبلغ',
            'rows' => [
                ['سباك', $withdrawal->plumber?->name ?: '—'],
                ['حالة', $withdrawal->statusLabel()],
            ],
            'badge' => 'يحتاج إجراء',
        ];

        $caption = WhatsAppMessage::make()
            ->header(WhatsAppMessage::ICON_WITHDRAWAL)
            ->event(WhatsAppMessage::ICON_WITHDRAWAL, 'طلب سحب جديد')
            ->param('السباك', $withdrawal->plumber?->name ?: '—')
            ->param('المبلغ', $withdrawal->formattedAmount())
            ->param('الحالة', $withdrawal->statusLabel())
            ->openInApp($deep)
            ->footer()
            ->toString();

        $asset = $this->brandCard->assetFor('withdrawal_pending', $card);
        $this->whatsapp->notifyAdmins($caption, $asset);
    }

    public function withdrawalStatusChanged(WithdrawalRequest $withdrawal, string $from, string $to): void
    {
        $withdrawal->loadMissing(['plumber']);
        $deep = DeepLink::for('withdrawal', $withdrawal->id);
        $card = [
            'title' => 'تحديث طلب السحب',
            'hero' => $withdrawal->formattedAmount(),
            'hero_label' => 'المبلغ',
            'rows' => [
                ['من', $from],
                ['إلى', $withdrawal->statusLabel()],
            ],
        ];

        $this->dispatch(
            array_filter([
                $withdrawal->plumber
                    ? ['phone' => $withdrawal->plumber->phone, 'name' => $withdrawal->plumber->name]
                    : null,
            ]),
            function (?string $name) use ($withdrawal, $from, $deep) {
                return WhatsAppMessage::make()
                    ->header(WhatsAppMessage::ICON_WITHDRAWAL)
                    ->greet($name)
                    ->event(WhatsAppMessage::ICON_WITHDRAWAL, 'تحديث طلب السحب')
                    ->param('المبلغ', $withdrawal->formattedAmount())
                    ->param('من', $from)
                    ->param('إلى', $withdrawal->statusLabel())
                    ->openInApp($deep)
                    ->footer()
                    ->toString();
            },
            'withdrawal_updated',
            $card
        );
    }

    /**
     * Generate the brand card once; personalize only the greeting line in captions.
     *
     * @param  list<array{phone?:string|null,name?:string|null}|null>  $recipients
     * @param  callable(?string): string  $captionFor
     * @param  array<string, mixed>  $cardPayload
     */
    protected function dispatch(array $recipients, callable $captionFor, string $themeKey, array $cardPayload): void
    {
        $asset = $this->brandCard->assetFor($themeKey, $cardPayload);
        $messages = [];
        $seen = [];

        foreach ($recipients as $recipient) {
            if (! is_array($recipient) || ! filled($recipient['phone'] ?? null)) {
                continue;
            }
            $phone = (string) $recipient['phone'];
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $messages[] = [
                'phone' => $phone,
                'text' => $captionFor($recipient['name'] ?? null),
                'image' => $asset,
            ];
        }

        foreach ($this->whatsapp->adminPhones() as $phone) {
            if (isset($seen[$phone])) {
                continue;
            }
            $seen[$phone] = true;
            $messages[] = [
                'phone' => $phone,
                'text' => $captionFor(null),
                'image' => $asset,
            ];
        }

        $this->whatsapp->sendMany($messages);
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
