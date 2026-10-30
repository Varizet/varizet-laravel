<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

/** `order.underpaid`: less than `uniqueAmount` arrived; hold the order. */
final class OrderUnderpaid extends OrderWebhookEvent
{
}
