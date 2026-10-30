<?php

declare(strict_types=1);

namespace Varizet\Laravel\Http\Controllers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Varizet\Exception\InvalidPayloadException;
use Varizet\Laravel\Events\EventMap;
use Varizet\Laravel\Events\WebhookReceived;
use Varizet\Laravel\Http\Middleware\VerifyVarizetSignature;
use Varizet\Model\Event;

/**
 * Dispatches `WebhookReceived` for every verified event and the typed event (`OrderPaid`, …) for
 * the six shop outcomes. A listener that throws answers 500 so Varizet retries (10 s … 24 h);
 * listeners must therefore be idempotent (dedupe on `$event->event->id`). Queue slow listeners
 * (`ShouldQueue`): Varizet waits at most 10 s for the answer.
 */
final class WebhookController
{
    public function __invoke(Request $request, Dispatcher $events): JsonResponse
    {
        $event = $request->attributes->get(VerifyVarizetSignature::ATTRIBUTE);
        if (!$event instanceof Event) {
            // The route must run behind the signature middleware.
            return new JsonResponse(['error' => 'not_verified'], 401);
        }

        try {
            $typed = EventMap::make($event);
        } catch (InvalidPayloadException) {
            // An order.* event whose object is not an order: retrying will not help.
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }
        $events->dispatch(new WebhookReceived($event));
        if ($typed !== null) {
            $events->dispatch($typed);
        }

        return new JsonResponse(['received' => true, 'type' => $event->type]);
    }
}
