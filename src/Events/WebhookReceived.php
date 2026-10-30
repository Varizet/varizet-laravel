<?php

declare(strict_types=1);

namespace Varizet\Laravel\Events;

use Varizet\Model\Event;

/** Every verified webhook, including `webhook.ping` and types without a typed event. */
final class WebhookReceived
{
    public function __construct(public readonly Event $event)
    {
    }
}
