<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

/** `order.needs_review`: a deposit needs a human decision in the panel; hold the order. */
final class OrderNeedsReview extends OrderWebhookEvent
{
}
