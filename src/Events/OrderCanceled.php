<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

/** `order.canceled`: the order was canceled (API or panel). */
final class OrderCanceled extends OrderWebhookEvent
{
}
