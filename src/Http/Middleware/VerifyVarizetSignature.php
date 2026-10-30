<?php

declare(strict_types=1);

namespace Varizet\Laravel\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Varizet\Exception\InvalidPayloadException;
use Varizet\Exception\SignatureVerificationException;
use Varizet\Webhook\Signature;
use Varizet\Webhook\Webhook;

/**
 * Verifies `Varizet-Signature` over the raw body (current and previous secret during rotation,
 * ±tolerance) and stores the parsed event as the `varizet_event` request attribute.
 * 401 for a bad signature, 400 for a signed body that is not an event, 500 when no secret is set
 * (Varizet retries, so configuring the secret later still delivers the events).
 */
final class VerifyVarizetSignature
{
    public const ATTRIBUTE = 'varizet_event';

    public function __construct(private readonly Config $config, private readonly LoggerInterface $logger)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secrets = array_values(array_filter([
            $this->config->get('varizet.webhook.secret'),
            $this->config->get('varizet.webhook.previous_secret'),
        ], static fn ($s): bool => is_string($s) && $s !== ''));
        if ($secrets === []) {
            $this->logger->error('Varizet webhook received but VARIZET_WEBHOOK_SECRET is not set');
            return new JsonResponse(['error' => 'not_configured'], 500);
        }

        try {
            $event = Webhook::constructEvent(
                $request->getContent(),
                $request->headers->get(Signature::HEADER),
                $secrets,
                (int) $this->config->get('varizet.webhook.tolerance', Signature::TOLERANCE_SECONDS),
            );
        } catch (SignatureVerificationException $e) {
            $this->logger->warning('Varizet webhook rejected', ['reason' => $e->reason, 'skew' => $e->skewSeconds]);
            return new JsonResponse(['error' => 'invalid_signature', 'reason' => $e->reason], 401);
        } catch (InvalidPayloadException $e) {
            $this->logger->warning('Varizet webhook payload rejected', ['error' => $e->getMessage()]);
            return new JsonResponse(['error' => 'invalid_payload'], 400);
        }

        $request->attributes->set(self::ATTRIBUTE, $event);
        return $next($request);
    }
}
