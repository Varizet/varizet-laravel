<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

use Varizet\Model\Event;
use Varizet\Model\EventType;

/** Webhook type → typed Laravel event. */
final class EventMap
{
    /** @var array<string, class-string<OrderWebhookEvent>> */
    public const ORDER_EVENTS = [
        EventType::ORDER_PAID => OrderPaid::class,
        EventType::ORDER_NEEDS_REVIEW => OrderNeedsReview::class,
        EventType::ORDER_UNDERPAID => OrderUnderpaid::class,
        EventType::ORDER_EXPIRED => OrderExpired::class,
        EventType::ORDER_CANCELED => OrderCanceled::class,
    ];

    private function __construct()
    {
    }

    public static function make(Event $event): ?object
    {
        if ($event->type === EventType::REFUND_CREATED) {
            return new RefundCreated($event, $event->object);
        }
        $class = self::ORDER_EVENTS[$event->type] ?? null;
        if ($class === null) {
            return null;
        }
        $order = $event->order();
        return $order === null ? null : new $class($event, $order);
    }
}
