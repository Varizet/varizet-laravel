<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

use Varizet\Model\Event;

/**
 * `refund.created`: a refund was recorded (the merchant moved the money outside Varizet).
 * `$refund` is the raw `data.object` (`id`, `amount` in rial, `order_id`, …).
 */
final class RefundCreated
{
    /**
     * @param array<string, mixed> $refund
     */
    public function __construct(public readonly Event $event, public readonly array $refund)
    {
    }
}
