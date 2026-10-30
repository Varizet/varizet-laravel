<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

use Varizet\Model\Event;
use Varizet\Model\Order;

/**
 * Base of the `order.*` events. `$order` is the order as it was when the event was sent;
 * delivery is unordered and at least once, so compare with your stored state (and
 * `OrderStatus::isRegression()`) before acting, and dedupe on `$event->id`.
 */
abstract class OrderWebhookEvent
{
    public function __construct(public readonly Event $event, public readonly Order $order)
    {
    }
}
