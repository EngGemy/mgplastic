<?php

namespace App\Services\Notifications;

use App\Concerns\Notifications\DispatchesDomainNotifications;
use App\Models\Invoice;
use App\Models\InvoiceDistribution;
use App\Models\Order;
use App\Models\User;
use App\Models\WithdrawalRequest;
use App\Notifications\Catalog\NotificationType;

/**
 * Maps high-value domain events → catalog NotificationType + recipients.
 * Call sites stay thin; templates/prefs/quiet-hours live in NotificationDispatcher.
 */
class DomainEventNotifier
{
    use DispatchesDomainNotifications;

    public function orderCreated(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $data = $this->orderData($order);

        if ($order->isFactoryChannel()) {
            $this->notifyDomainAdmins(NotificationType::OrderCreated, $data, [
                'filament_status' => 'info',
            ]);

            return;
        }

        $this->notifyDomain($order->supplier, NotificationType::OrderCreated, $data, [
            'filament_status' => 'info',
        ]);
    }

    public function orderApproved(Order $order): void
    {
        $order->loadMissing('requester');
        $this->notifyDomain($order->requester, NotificationType::OrderApproved, $this->orderData($order), [
            'filament_status' => 'success',
        ]);
    }

    public function orderRejected(Order $order, ?string $reason = null): void
    {
        $order->loadMissing('requester');
        $data = $this->orderData($order);
        if ($reason) {
            $data['reason'] = $reason;
        }

        $this->notifyDomain($order->requester, NotificationType::OrderRejected, $data, [
            'filament_status' => 'danger',
            'body' => $reason
                ? "الطلب {$order->order_number} رُفض — السبب: {$reason}"
                : null,
        ]);
    }

    public function orderShipped(Order $order): void
    {
        $order->loadMissing('requester');
        $tracking = $order->tracking_number ? " — رقم التتبّع: {$order->tracking_number}" : '';

        $this->notifyDomain($order->requester, NotificationType::OrderShipped, $this->orderData($order), [
            'filament_status' => 'info',
            'body' => "الطلب {$order->order_number} تم شحنه وهو في طريقه إليك{$tracking}",
        ]);
    }

    public function orderDelivered(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $data = $this->orderData($order);

        $this->notifyDomain($order->requester, NotificationType::OrderDelivered, $data, [
            'filament_status' => 'success',
        ]);

        if ($order->isFactoryChannel()) {
            $this->notifyDomainAdmins(NotificationType::OrderDelivered, $data, [
                'filament_status' => 'success',
                'title' => 'تم استلام الطلب',
                'body' => "طلب رقم {$order->order_number} تم استلامه من {$order->requester?->name}.",
            ]);

            return;
        }

        $this->notifyDomain($order->supplier, NotificationType::OrderDelivered, $data, [
            'filament_status' => 'success',
            'title' => 'تم استلام الطلب',
            'body' => "طلب رقم {$order->order_number} تم استلامه من {$order->requester?->name}.",
        ]);
    }

    public function orderCancelled(Order $order): void
    {
        $order->loadMissing(['requester', 'supplier']);
        $data = $this->orderData($order);

        if ($order->isFactoryChannel()) {
            $this->notifyDomainAdmins(NotificationType::OrderRejected, $data, [
                'filament_status' => 'warning',
                'title' => 'تم إلغاء طلب',
                'body' => "طلب رقم {$order->order_number} أُلغي من قبل {$order->requester?->name}.",
            ]);

            return;
        }

        $this->notifyDomain($order->supplier, NotificationType::OrderRejected, $data, [
            'filament_status' => 'warning',
            'title' => 'تم إلغاء طلب',
            'body' => "طلب رقم {$order->order_number} أُلغي من قبل {$order->requester?->name}.",
        ]);
    }

    public function invoiceSubmitted(Invoice $invoice): void
    {
        $invoice->loadMissing(['plumber', 'wholesaleDistributor', 'issuer']);
        $data = $this->invoiceData($invoice);

        $this->notifyDomainAdmins(NotificationType::InvoiceSubmitted, $data, [
            'filament_status' => 'info',
        ]);

        $owner = $this->invoiceOwner($invoice);
        $this->notifyDomain($owner, NotificationType::InvoiceSubmitted, $data, [
            'filament_status' => 'info',
            'title' => 'تم استلام الفاتورة',
            'body' => "الفاتورة {$data['invoice_number']} قيد المراجعة.",
        ]);
    }

    public function invoiceApproved(Invoice $invoice): void
    {
        $invoice->loadMissing(['plumber', 'wholesaleDistributor', 'issuer']);
        $owner = $this->invoiceOwner($invoice);

        $this->notifyDomain($owner, NotificationType::InvoiceApproved, $this->invoiceData($invoice), [
            'filament_status' => 'success',
        ]);
    }

    public function invoiceRejected(Invoice $invoice, ?string $reason = null): void
    {
        $invoice->loadMissing(['plumber', 'wholesaleDistributor', 'issuer']);
        $owner = $this->invoiceOwner($invoice);
        $data = $this->invoiceData($invoice);
        if ($reason) {
            $data['reason'] = $reason;
        }

        $this->notifyDomain($owner, NotificationType::InvoiceRejected, $data, [
            'filament_status' => 'danger',
            'body' => $reason
                ? "رُفضت الفاتورة {$data['invoice_number']} — {$reason}"
                : null,
        ]);
    }

    public function pointsAwarded(User $user, int $points, array $meta = []): void
    {
        if ($points <= 0) {
            return;
        }

        $this->notifyDomain($user, NotificationType::PointsAdded, array_merge([
            'points' => $points,
            'entity_id' => $meta['entity_id'] ?? ($meta['distribution_id'] ?? $meta['order_id'] ?? null),
            'type' => 'points',
            'screen' => 'points',
        ], $meta), [
            'filament_status' => 'success',
        ]);
    }

    public function pointsRedeemed(User $user, int $points, array $meta = []): void
    {
        if ($points <= 0) {
            return;
        }

        $this->notifyDomain($user, NotificationType::PointsRedeemed, array_merge([
            'points' => $points,
            'entity_id' => $meta['entity_id'] ?? null,
            'type' => 'points',
            'screen' => 'points',
        ], $meta), [
            'filament_status' => 'info',
        ]);
    }

    public function distributionConfirmed(InvoiceDistribution $distribution): void
    {
        $distribution->loadMissing(['invoice', 'plumber']);

        if (! $distribution->plumber) {
            return;
        }

        $invoice = $distribution->invoice;
        $number = $invoice?->number ?? $invoice?->serial_number ?? ('#'.($invoice?->id ?? $distribution->id));

        $this->notifyDomain($distribution->plumber, NotificationType::InvoiceApproved, [
            'invoice_number' => (string) $number,
            'invoice_id' => (int) ($invoice?->id ?? 0),
            'entity_id' => (int) $distribution->id,
            'distribution_id' => (int) $distribution->id,
            'type' => 'invoice',
            'entity_type' => 'invoice',
            'screen' => 'invoice',
        ], [
            'filament_status' => 'success',
            'title' => 'تم تأكيد التوزيع',
            'body' => "تم تأكيد توزيع الفاتورة {$number}.",
        ]);
    }

    public function distributionPointsAwarded(InvoiceDistribution $distribution, int $points): void
    {
        $distribution->loadMissing('plumber');
        if (! $distribution->plumber) {
            return;
        }

        $this->pointsAwarded($distribution->plumber, $points, [
            'distribution_id' => $distribution->id,
            'entity_id' => $distribution->id,
            'invoice_id' => $distribution->invoice_id,
        ]);
    }

    public function withdrawalRequested(WithdrawalRequest $req): void
    {
        $req->loadMissing('plumber');
        $data = $this->withdrawalData($req);

        $this->notifyDomain($req->plumber, NotificationType::WithdrawalRequested, $data, [
            'filament_status' => 'info',
        ]);
        $this->notifyDomainAdmins(NotificationType::AdminPendingReview, array_merge($data, [
            'title' => 'طلب سحب جديد #'.$req->id,
        ]), [
            'filament_status' => 'warning',
        ]);
    }

    public function withdrawalPaid(WithdrawalRequest $req): void
    {
        $req->loadMissing('plumber');
        $this->notifyDomain($req->plumber, NotificationType::WithdrawalPaid, $this->withdrawalData($req), [
            'filament_status' => 'success',
            'filament_url' => method_exists($req, 'receiptUrl') ? $req->receiptUrl() : null,
            'filament_action_label' => 'عرض الإيصال',
        ]);
    }

    public function withdrawalRejected(WithdrawalRequest $req, ?string $reason = null): void
    {
        $req->loadMissing('plumber');
        $data = $this->withdrawalData($req);
        if ($reason) {
            $data['reason'] = $reason;
        }

        $this->notifyDomain($req->plumber, NotificationType::WithdrawalRejected, $data, [
            'filament_status' => 'danger',
            'body' => $reason
                ? "رُفض طلب السحب {$data['amount']} — السبب: {$reason}"
                : null,
        ]);
    }

    public function accountSuspended(User $user, ?string $reason = null): void
    {
        $this->notifyDomain($user, NotificationType::AccountSuspended, [
            'entity_id' => $user->id,
            'reason' => $reason,
            'type' => 'home',
            'screen' => 'home',
        ], [
            'filament_status' => 'warning',
            'body' => $reason
                ? "حسابك موقوف — {$reason}"
                : null,
        ]);
    }

    public function accountActivated(User $user, string $title, string $body): void
    {
        $this->notifyDomain($user, NotificationType::CampaignBroadcast, [
            'title' => $title,
            'body' => $body,
            'entity_id' => $user->id,
            'type' => 'home',
            'screen' => 'home',
        ], [
            'title' => $title,
            'body' => $body,
            'filament_status' => 'success',
        ]);
    }

    public function adminPendingReview(string $title, string $body, array $meta = []): void
    {
        $this->notifyDomainAdmins(NotificationType::AdminPendingReview, array_merge([
            'title' => $title,
            'body' => $body,
            'entity_id' => $meta['entity_id'] ?? null,
            'type' => 'home',
            'screen' => 'home',
        ], $meta), [
            'title' => $title,
            'body' => $body,
            'filament_status' => 'warning',
            'filament_url' => $meta['url'] ?? null,
            'filament_action_label' => $meta['action_label'] ?? 'مراجعة الطلب',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function orderData(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'order_id' => (int) $order->id,
            'entity_id' => (int) $order->id,
            'requester' => $order->requester?->name ?? '',
            'quantity' => (int) $order->total_quantity,
            'channel' => $order->channel,
            'order_status' => $order->status,
            'type' => 'order',
            'entity_type' => 'order',
            'screen' => 'order',
        ];
    }

    protected function invoiceOwner(Invoice $invoice): ?User
    {
        return $invoice->plumber
            ?? $invoice->wholesaleDistributor
            ?? $invoice->issuer;
    }

    /**
     * @return array<string, mixed>
     */
    protected function invoiceData(Invoice $invoice): array
    {
        $number = $invoice->number ?? $invoice->serial_number ?? ('#'.$invoice->id);

        return [
            'invoice_number' => (string) $number,
            'invoice_id' => (int) $invoice->id,
            'entity_id' => (int) $invoice->id,
            'type' => 'invoice',
            'entity_type' => 'invoice',
            'screen' => 'invoice',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function withdrawalData(WithdrawalRequest $req): array
    {
        $amount = method_exists($req, 'formattedAmount')
            ? $req->formattedAmount()
            : (string) ($req->amount_cents ?? $req->id);

        return [
            'amount' => $amount,
            'withdrawal_id' => (int) $req->id,
            'entity_id' => (int) $req->id,
            'type' => 'withdrawal',
            'entity_type' => 'withdrawal',
            'screen' => 'withdrawal',
        ];
    }
}
