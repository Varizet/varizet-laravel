<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use Varizet\Http\HttpClientInterface;
use Varizet\Laravel\Tests\Support\FakeHttp;
use Varizet\Laravel\VarizetServiceProvider;

abstract class TestCase extends Orchestra
{
    public const SECRET = 'whsec_laravelTestSecret0000000000000';

    protected FakeHttp $http;

    protected function setUp(): void
    {
        parent::setUp();
        // As in `php artisan`, where the root URL comes from APP_URL.
        \Illuminate\Support\Facades\URL::forceRootUrl('https://shop.test');
        \Illuminate\Support\Facades\URL::forceScheme('https');
    }

    protected function getPackageProviders($app): array
    {
        return [VarizetServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Varizet' => \Varizet\Laravel\Facades\Varizet::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.url', 'https://shop.test');
        $app['config']->set('varizet.api_key', 'vz_test_laravelPackageKey01');
        $app['config']->set('varizet.base_url', 'https://api.varizet.test');
        $app['config']->set('varizet.max_retries', 0);
        $app['config']->set('varizet.webhook.secret', self::SECRET);
        $this->http = new FakeHttp();
        $app->instance(HttpClientInterface::class, $this->http);
    }

    /** @return array<string, mixed> */
    protected static function order(array $overrides = []): array
    {
        return array_replace([
            'id' => 'ord_01JBX4Q8M6',
            'code' => 'VZ-8K2Q',
            'status' => 'awaiting_payment',
            'amount' => 8500000,
            'unique_amount' => 8500430,
            'unique_mode' => 'increase',
            'currency' => 'IRR',
            'payment_url' => 'https://pay.varizet.ir/p/kJ8s2QaX7mZr4TnB',
            'destination' => ['card_id' => 'card_01J', 'bank' => 'mellat', 'last4' => '1234', 'owner_name' => 'سارا'],
            'expires_at' => '2026-10-28T11:02:00Z',
            'grace_until' => '2026-10-28T12:02:00Z',
            'paid_at' => null,
            'paid_amount' => null,
            'paid_late' => false,
            'payer' => null,
            'match' => null,
            'merchant_order_id' => 'INV-12345',
            'customer' => null,
            'metadata' => [],
            'livemode' => false,
            'created_at' => '2026-10-28T10:32:00Z',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    protected static function endpoint(array $overrides = []): array
    {
        return array_replace([
            'id' => 'whe_01JLARAVEL',
            'url' => 'https://shop.test/varizet/webhook',
            'description' => null,
            'events' => ['order.paid', 'order.needs_review', 'order.underpaid', 'order.expired', 'order.canceled', 'refund.created'],
            'status' => 'enabled',
            'api_version' => '2026-10-01',
            'livemode' => false,
            'secret_rotation' => null,
            'created_at' => '2026-10-28T10:00:00Z',
        ], $overrides);
    }
}
