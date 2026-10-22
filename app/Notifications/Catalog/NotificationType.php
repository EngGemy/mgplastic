<?php

namespace App\Notifications\Catalog;

enum NotificationType: string
{
    case OrderCreated = 'order.created';
    case OrderApproved = 'order.approved';
    case OrderRejected = 'order.rejected';
    case OrderShipped = 'order.shipped';
    case OrderDelivered = 'order.delivered';

    case InvoiceSubmitted = 'invoice.submitted';
    case InvoiceApproved = 'invoice.approved';
    case InvoiceRejected = 'invoice.rejected';

    case PointsAdded = 'points.added';
    case PointsRedeemed = 'points.redeemed';

    case WithdrawalRequested = 'withdrawal.requested';
    case WithdrawalPaid = 'withdrawal.paid';
    case WithdrawalRejected = 'withdrawal.rejected';

    case AccountSuspended = 'account.suspended';
    case CampaignBroadcast = 'campaign.broadcast';
    case AdminPendingReview = 'admin.pending_review';

    case NetworkJoinRequested = 'network.join_requested';
    case NetworkJoinApproved = 'network.join_approved';
    case NetworkJoinRejected = 'network.join_rejected';

    public function definition(): NotificationDefinition
    {
        return NotificationCatalog::get($this);
    }

    public function priority(): string
    {
        return $this->definition()->priority;
    }

    public function isHighPriority(): bool
    {
        return $this->priority() === 'high';
    }

    /** @return list<string> */
    public function channels(): array
    {
        return $this->definition()->channels;
    }

    public function screen(): string
    {
        return $this->definition()->screen;
    }

    public function defaultPush(): bool
    {
        return $this->definition()->defaultPush;
    }

    public function defaultInApp(): bool
    {
        return $this->definition()->defaultInApp;
    }

    public function defaultEmail(): bool
    {
        return $this->definition()->defaultEmail;
    }
}
