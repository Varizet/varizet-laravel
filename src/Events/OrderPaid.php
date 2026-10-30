<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

/** `order.paid`: money arrived (`paidAmount`, `paidAt`, `match`); fulfil the order. */
final class OrderPaid extends OrderWebhookEvent
{
}
