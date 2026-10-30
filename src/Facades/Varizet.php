<?php

declare(strict_types=1);

namespace Varizet\Laravel\Facades;

use Illuminate\Support\Facades\Facade;
use Varizet\VarizetClient;

/**
 * @method static \Varizet\Service\OrderService orders()
 * @method static \Varizet\Service\RefundService refunds()
 * @method static \Varizet\Service\WebhookEndpointService webhookEndpoints()
 * @method static \Varizet\Service\EventService events()
 * @method static \Varizet\Service\TestHelperService testHelpers()
 * @method static \Varizet\Model\ApiKeyIdentity me()
 * @method static bool isLivemode()
 * @method static \Varizet\Http\ResponseMeta|null lastResponse()
 *
 * @see VarizetClient
 */
final class Varizet extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VarizetClient::class;
    }
}
