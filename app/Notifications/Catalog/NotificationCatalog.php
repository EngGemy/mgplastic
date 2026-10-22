<?php

namespace App\Notifications\Catalog;

final class NotificationCatalog
{
    /** @var array<string, NotificationDefinition>|null */
    private static ?array $cache = null;

    public static function get(NotificationType $type): NotificationDefinition
    {
        return self::all()[$type->value];
    }

    /**
     * @return array<string, NotificationDefinition>
     */
    public static function all(): array
    {
        return self::$cache ??= self::build();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function toArray(): array
    {
        return array_values(array_map(function (NotificationDefinition $d) {
            return [
                'type' => $d->type->value,
                'title' => $d->title,
                'body' => $d->body,
                'priority' => $d->priority,
                'channels' => $d->channels,
                'default' => [
                    'push' => $d->defaultPush,
                    'in_app' => $d->defaultInApp,
                    'email' => $d->defaultEmail,
                ],
                'screen' => $d->screen,
            ];
        }, self::all()));
    }

    /**
     * @return array<string, NotificationDefinition>
     */
    private static function build(): array
    {
        $defs = [
            self::def(NotificationType::OrderCreated, [
                'ar' => 'طلب جديد',
                'en' => 'New order',
            ], [
                'ar' => 'طلب :order_number من :requester — :quantity وحدة',
                'en' => 'Order :order_number from :requester — :quantity units',
            ], 'high', ['push', 'in_app'], 'order'),

            self::def(NotificationType::OrderApproved, [
                'ar' => 'تم تأكيد طلبك',
                'en' => 'Order confirmed',
            ], [
                'ar' => 'الطلب :order_number تم تأكيده',
                'en' => 'Order :order_number was confirmed',
            ], 'high', ['push', 'in_app'], 'order'),

            self::def(NotificationType::OrderRejected, [
                'ar' => 'تم رفض الطلب',
                'en' => 'Order rejected',
            ], [
                'ar' => 'الطلب :order_number رُفض',
                'en' => 'Order :order_number was rejected',
            ], 'high', ['push', 'in_app'], 'order'),

            self::def(NotificationType::OrderShipped, [
                'ar' => 'تم شحن طلبك',
                'en' => 'Order shipped',
            ], [
                'ar' => 'الطلب :order_number في الطريق إليك',
                'en' => 'Order :order_number is on the way',
            ], 'high', ['push', 'in_app'], 'order'),

            self::def(NotificationType::OrderDelivered, [
                'ar' => 'تم استلام الطلب',
                'en' => 'Order delivered',
            ], [
                'ar' => 'تم تأكيد استلام الطلب :order_number',
                'en' => 'Delivery confirmed for order :order_number',
            ], 'normal', ['push', 'in_app'], 'order'),

            self::def(NotificationType::InvoiceSubmitted, [
                'ar' => 'فاتورة جديدة',
                'en' => 'New invoice',
            ], [
                'ar' => 'فاتورة :invoice_number بانتظار المراجعة',
                'en' => 'Invoice :invoice_number awaiting review',
            ], 'normal', ['push', 'in_app'], 'invoice'),

            self::def(NotificationType::InvoiceApproved, [
                'ar' => 'تم اعتماد الفاتورة',
                'en' => 'Invoice approved',
            ], [
                'ar' => 'تم اعتماد الفاتورة :invoice_number',
                'en' => 'Invoice :invoice_number was approved',
            ], 'high', ['push', 'in_app'], 'invoice'),

            self::def(NotificationType::InvoiceRejected, [
                'ar' => 'تم رفض الفاتورة',
                'en' => 'Invoice rejected',
            ], [
                'ar' => 'رُفضت الفاتورة :invoice_number',
                'en' => 'Invoice :invoice_number was rejected',
            ], 'high', ['push', 'in_app'], 'invoice'),

            self::def(NotificationType::PointsAdded, [
                'ar' => 'تمت إضافة نقاط',
                'en' => 'Points added',
            ], [
                'ar' => 'أُضيفت :points نقطة إلى رصيدك',
                'en' => ':points points were added to your balance',
            ], 'normal', ['push', 'in_app'], 'points'),

            self::def(NotificationType::PointsRedeemed, [
                'ar' => 'تم تحويل النقاط',
                'en' => 'Points redeemed',
            ], [
                'ar' => 'تم تحويل :points نقطة',
                'en' => ':points points were redeemed',
            ], 'normal', ['push', 'in_app'], 'points'),

            self::def(NotificationType::WithdrawalRequested, [
                'ar' => 'طلب سحب جديد',
                'en' => 'Withdrawal requested',
            ], [
                'ar' => 'طلب سحب بقيمة :amount بانتظار المراجعة',
                'en' => 'Withdrawal of :amount awaiting review',
            ], 'normal', ['push', 'in_app'], 'withdrawal'),

            self::def(NotificationType::WithdrawalPaid, [
                'ar' => 'تم دفع السحب',
                'en' => 'Withdrawal paid',
            ], [
                'ar' => 'تم تحويل مبلغ السحب :amount',
                'en' => 'Withdrawal :amount was paid',
            ], 'high', ['push', 'in_app'], 'withdrawal'),

            self::def(NotificationType::WithdrawalRejected, [
                'ar' => 'تم رفض السحب',
                'en' => 'Withdrawal rejected',
            ], [
                'ar' => 'رُفض طلب السحب :amount',
                'en' => 'Withdrawal :amount was rejected',
            ], 'high', ['push', 'in_app'], 'withdrawal'),

            self::def(NotificationType::AccountSuspended, [
                'ar' => 'تم إيقاف الحساب',
                'en' => 'Account suspended',
            ], [
                'ar' => 'حسابك موقوف — تواصل مع الدعم',
                'en' => 'Your account is suspended — contact support',
            ], 'high', ['push', 'in_app'], 'home'),

            self::def(NotificationType::CampaignBroadcast, [
                'ar' => ':title',
                'en' => ':title',
            ], [
                'ar' => ':body',
                'en' => ':body',
            ], 'normal', ['push', 'in_app'], 'home'),

            self::def(NotificationType::AdminPendingReview, [
                'ar' => 'طلب بانتظار المراجعة',
                'en' => 'Pending admin review',
            ], [
                'ar' => ':title',
                'en' => ':title',
            ], 'normal', ['push', 'in_app'], 'home'),
        ];

        $map = [];
        foreach ($defs as $def) {
            $map[$def->type->value] = $def;
        }

        return $map;
    }

    /**
     * @param  array{ar: string, en: string}  $title
     * @param  array{ar: string, en: string}  $body
     * @param  list<string>  $channels
     */
    private static function def(
        NotificationType $type,
        array $title,
        array $body,
        string $priority,
        array $channels,
        string $screen,
        bool $defaultPush = true,
        bool $defaultInApp = true,
        bool $defaultEmail = false,
    ): NotificationDefinition {
        return new NotificationDefinition(
            type: $type,
            title: $title,
            body: $body,
            priority: $priority,
            channels: $channels,
            defaultPush: $defaultPush,
            defaultInApp: $defaultInApp,
            defaultEmail: $defaultEmail,
            screen: $screen,
        );
    }
}
