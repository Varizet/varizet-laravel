<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests\Feature;

use Varizet\Exception\InvalidArgumentException;
use Varizet\Laravel\Facades\Varizet;
use Varizet\Laravel\Tests\TestCase;
use Varizet\Laravel\VarizetServiceProvider;
use Varizet\VarizetClient;

final class ServiceProviderTest extends TestCase
{
    public function testClientIsASingletonConfiguredFromConfig(): void
    {
        $client = $this->app->make(VarizetClient::class);
        self::assertSame($client, $this->app->make('varizet'));
        self::assertFalse($client->isLivemode());

        $this->http->json(201, self::order());
        $order = Varizet::orders()->create(['amount' => 8_500_000, 'merchant_order_id' => 'INV-12345'], 'laravel-INV-12345-1');
        self::assertSame(8500430, $order->uniqueAmount);

        $request = $this->http->requests[0];
        self::assertSame('https://api.varizet.test/v1/orders', $request->url);
        self::assertSame('laravel-INV-12345-1', $request->headers['Idempotency-Key']);
        self::assertMatchesRegularExpression(
            '#^laravel/' . preg_quote(VarizetServiceProvider::VERSION, '#') . '; laravel=\d+\.\d+\.\d+[^;]*; sdk=\d+\.\d+\.\d+; php=\d+\.\d+$#',
            $request->headers['Varizet-Plugin'],
        );
    }

    public function testFacadeExposesTheClient(): void
    {
        $this->http->json(200, ['store' => ['id' => 'sto_1', 'name' => 'کتاب‌نما', 'slug' => 'ketabnama'], 'livemode' => false, 'scopes' => ['orders:read'], 'key' => ['prefix' => 'vz_test_lara', 'last4' => 'ey01']]);
        self::assertSame('کتاب‌نما', Varizet::me()->storeName);
        self::assertSame('req_FAKE', Varizet::lastResponse()?->requestId);
    }

    public function testMissingKeyFailsOnlyWhenTheClientIsUsed(): void
    {
        $this->app['config']->set('varizet.api_key', null);
        $this->app->forgetInstance(VarizetClient::class);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('VARIZET_API_KEY');
        $this->app->make(VarizetClient::class);
    }

    public function testConfigIsPublishableAndMerged(): void
    {
        self::assertSame('varizet/webhook', config('varizet.webhook.path'));
        self::assertContains('order.paid', config('varizet.webhook.events'));
        $paths = VarizetServiceProvider::pathsToPublish(VarizetServiceProvider::class, 'varizet-config');
        self::assertCount(1, $paths);
        self::assertFileExists((string) array_key_first($paths));
    }
}
