<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

/** `order.expired`: the grace period ended without payment; release stock. */
final class OrderExpired extends OrderWebhookEvent
{
}
