<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\User;
use App\Support\DeepLink;
use App\Support\PriceListWhatsApp;
use App\Support\WhatsAppMessage;

class InvoiceWhatsAppShareService
{
    public function __construct(
        protected CustomerWhatsAppNotifier $notifier,
        protected WhatsAppBusinessAlerts $alerts,
    ) {}

    /**
     * @return array{
     *   can_share: bool,
     *   share_url: ?string,
     *   whatsapp_url: ?string,
     *   deep_link: ?string,
     *   message: string,
     *   recipient: array{name:?string,phone:?string,phone_masked:?string},
     *   invoice_id: int,
     *   invoice_number: ?string
     * }
     */
    public function forInvoice(Invoice $invoice, User $actor, ?string $phoneOverride = null): array
    {
        $this->assertCanShareInvoice($invoice, $actor);
        $invoice->loadMissing(['wholesaleDistributor', 'counterparty', 'plumber', 'issuer', 'sourceDistribution']);

        $recipient = $this->defaultRecipient($invoice, $actor);
        $phone = $phoneOverride ?: ($recipient['phone'] ?? null);
        $shareUrl = $this->notifier->invoiceShareUrl($invoice);
        $message = $this->invoiceMessage($invoice, $shareUrl, $recipient['name'] ?? null);
        $whatsappUrl = $phone ? PriceListWhatsApp::url($phone, $message) : null;

        return [
            'can_share' => true,
            'share_url' => $shareUrl,
            'whatsapp_url' => $whatsappUrl,
            'deep_link' => DeepLink::for('invoice', $invoice->id),
            'message' => $message,
            'recipient' => [
                'name' => $recipient['name'] ?? null,
                'phone' => $phone,
                'phone_masked' => $this->maskPhone($phone),
            ],
            'invoice_id' => (int) $invoice->id,
            'invoice_number' => $invoice->number,
        ];
    }

    /**
     * @return array{
     *   can_share: bool,
     *   share_url: ?string,
     *   whatsapp_url: ?string,
     *   deep_link: ?string,
     *   message: string,
     *   recipient: array{name:?string,phone:?string,phone_masked:?string},
     *   distribution_id: int,
     *   invoice_id: ?int
     * }
     */
    public function forDistribution(InvoiceDistribution $distribution, User $actor, ?string $phoneOverride = null): array
    {
        $this->assertCanShareDistribution($distribution, $actor);
        $distribution->loadMissing(['fromUser', 'toUser', 'invoice']);

        // Prefer linked outgoing invoice when tier 2.
        if ((int) $distribution->tier === 2) {
            $outgoing = Invoice::query()->where('source_distribution_id', $distribution->id)->first();
            if ($outgoing) {
                $payload = $this->forInvoice($outgoing, $actor, $phoneOverride);
                $payload['distribution_id'] = (int) $distribution->id;

                return $payload;
            }
        }

        $recipientUser = (int) $actor->id === (int) $distribution->from_user_id
            ? $distribution->toUser
            : $distribution->fromUser;
        $phone = $phoneOverride ?: $recipientUser?->phone;
        $shareUrl = $distribution->invoice_id
            ? $this->notifier->invoiceShareUrl($distribution->invoice)
            : null;
        $points = (int) $distribution->items->sum('points_value');
        $qty = (int) $distribution->items->sum('quantity');
        $builder = WhatsAppMessage::make()
            ->header('🧾')
            ->greet($recipientUser?->name)
            ->event('🧾', 'توريد / فاتورة MG')
            ->line('من: *'.($distribution->fromUser?->name ?: '—').'*')
            ->line('كمية: *'.$qty.'* · نقاط: *'.$points.'*');
        if ($shareUrl) {
            $builder->line('الرابط:')->line($shareUrl);
        }
        $message = $builder
            ->openInApp(DeepLink::for('supply', $distribution->id))
            ->footer()
            ->toString();

        return [
            'can_share' => true,
            'share_url' => $shareUrl,
            'whatsapp_url' => $phone ? PriceListWhatsApp::url($phone, $message) : null,
            'deep_link' => DeepLink::for('supply', $distribution->id),
            'message' => $message,
            'recipient' => [
                'name' => $recipientUser?->brand_name ?: $recipientUser?->name,
                'phone' => $phone,
                'phone_masked' => $this->maskPhone($phone),
            ],
            'distribution_id' => (int) $distribution->id,
            'invoice_id' => $distribution->invoice_id ? (int) $distribution->invoice_id : null,
            'invoice_number' => $distribution->invoice?->number,
        ];
    }

    /**
     * Re-notify via WAHA BusinessAlerts (invoice card) — respects daily limits.
     */
    public function resendInvoiceAlert(Invoice $invoice, User $actor): void
    {
        $this->assertCanShareInvoice($invoice, $actor);
        $this->alerts->invoiceCreated($invoice);
    }

    public function compactShareFields(Invoice $invoice, User $actor): array
    {
        try {
            $p = $this->forInvoice($invoice, $actor);
        } catch (\Throwable) {
            return [
                'can_share_whatsapp' => false,
                'share_url' => null,
                'whatsapp_url' => null,
            ];
        }

        return [
            'can_share_whatsapp' => true,
            'share_url' => $p['share_url'],
            'whatsapp_url' => $p['whatsapp_url'],
            'deep_link' => $p['deep_link'],
        ];
    }

    public function assertCanShareInvoice(Invoice $invoice, User $actor): void
    {
        $id = (int) $actor->id;
        $ok = in_array($id, array_filter([
            (int) $invoice->wholesale_distributor_id,
            (int) $invoice->counterparty_user_id,
            (int) $invoice->plumber_id,
            (int) $invoice->issued_by,
        ]), true);

        if (! $ok && $invoice->sourceDistribution) {
            $d = $invoice->sourceDistribution;
            $ok = in_array($id, [(int) $d->from_user_id, (int) $d->to_user_id], true);
        }

        if (! $ok && $actor->isCentralWarehouse()) {
            // warehouse may share invoices they issued as from_user on tier-1/2 distributions
            $ok = InvoiceDistribution::query()
                ->where('invoice_id', $invoice->id)
                ->where('from_user_id', $id)
                ->exists();
        }

        if (! $ok) {
            throw new \DomainException('غير مصرّح بمشاركة هذه الفاتورة');
        }
    }

    public function assertCanShareDistribution(InvoiceDistribution $distribution, User $actor): void
    {
        $id = (int) $actor->id;
        if (! in_array($id, [(int) $distribution->from_user_id, (int) $distribution->to_user_id], true)
            && ! in_array($actor->role, ['super_admin', 'admin'], true)) {
            throw new \DomainException('غير مصرّح بمشاركة هذا التوزيع');
        }
    }

    /** @return array{name:?string,phone:?string} */
    protected function defaultRecipient(Invoice $invoice, User $actor): array
    {
        // Seller shares → buyer; buyer shares → seller (or self if no counterparty).
        if ($invoice->isOutgoing()) {
            $buyer = $invoice->counterparty;
            $seller = $invoice->wholesaleDistributor;
            if ((int) $actor->id === (int) $seller?->id) {
                return ['name' => $buyer?->brand_name ?: $buyer?->name, 'phone' => $buyer?->phone];
            }

            return ['name' => $seller?->brand_name ?: $seller?->name, 'phone' => $seller?->phone ?: $buyer?->phone];
        }

        // Incoming factory/warehouse → wholesaler
        $buyer = $invoice->wholesaleDistributor;
        $issuer = $invoice->issuer;

        if ((int) $actor->id === (int) $buyer?->id) {
            return ['name' => $issuer?->name ?: $buyer?->name, 'phone' => $issuer?->phone ?: $buyer?->phone];
        }

        return ['name' => $buyer?->brand_name ?: $buyer?->name, 'phone' => $buyer?->phone];
    }

    protected function invoiceMessage(Invoice $invoice, ?string $shareUrl, ?string $greetName): string
    {
        $number = $invoice->number ?: '#'.$invoice->id;
        $points = (int) $invoice->documentPoints();
        $kind = $invoice->isOutgoing() ? 'فاتورة صادرة' : 'فاتورة واردة';

        $msg = WhatsAppMessage::make()
            ->header('🧾')
            ->greet($greetName)
            ->event('🧾', $kind.' — MG')
            ->line('رقم الفاتورة: *'.$number.'*')
            ->line('النقاط: *'.number_format($points).'*');

        if ($shareUrl) {
            $msg->line('راجع الفاتورة:')->line($shareUrl);
        }

        $pdfUrl = $this->notifier->invoicePdfUrl($invoice);
        if ($pdfUrl) {
            $msg->line('تحميل PDF:')->line($pdfUrl);
        }

        return $msg->openInApp(DeepLink::for('invoice', $invoice->id))->footer()->toString();
    }

    protected function maskPhone(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if (strlen($d) < 4) {
            return $d !== '' ? '****' : null;
        }

        return str_repeat('*', max(0, strlen($d) - 4)).substr($d, -4);
    }
}
