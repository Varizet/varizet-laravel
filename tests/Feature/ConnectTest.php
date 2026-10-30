<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests\Feature;

use Varizet\Connect\Pkce;
use Varizet\Laravel\Tests\TestCase;

final class ConnectTest extends TestCase
{
    private const TOKEN = [
        'api_key' => 'vz_test_laravelConnected000000000000',
        'webhook_endpoint_id' => 'whe_01K8F3M2Q0V7E6W5X4Y3Z2A1B0',
        'webhook_secret' => 'whsec_laravelConnected0000000000000',
        'integration_id' => 'int_01K8F3M2Q0V7E6W5X4Y3Z2A1B0',
        'store' => ['id' => 'sto_01K8F3M2Q0V7E6W5X4Y3Z2A1B0', 'name' => 'کتاب‌نما', 'slug' => 'ketab'],
        'livemode' => false,
        'scopes' => ['orders:read', 'orders:write'],
    ];

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('cache.default', 'file');
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('k', 32)));
        $app['config']->set('varizet.connect.app_url', 'https://app.varizet.test');
    }

    /** Plays the browser: while the command waits, approve in the panel and hit the callback. */
    private function approveWith(array $callbackQuery): void
    {
        $this->app->instance('varizet.connect.wait', function (string $state) use ($callbackQuery): void {
            $this->get('/varizet/connect/callback?' . http_build_query($callbackQuery + ['state' => $state]));
        });
    }

    public function testConnectsAndPrintsTheKeyAndSecretOnce(): void
    {
        $this->http->json(200, self::TOKEN);
        $this->approveWith(['code' => 'vzc_ok']);
        $this->artisan('varizet:connect', ['--timeout' => 30])
            ->expectsOutputToContain('این آدرس را در مرورگر باز کنید')
            ->expectsOutputToContain('https://app.varizet.test/connect?client=laravel')
            ->expectsOutputToContain('اتصال برقرار شد: فروشگاه «کتاب‌نما»، حالت Test.')
            ->expectsOutput('VARIZET_API_KEY=vz_test_laravelConnected000000000000')
            ->expectsOutput('VARIZET_WEBHOOK_SECRET=whsec_laravelConnected0000000000000')
            ->assertSuccessful();
    }

    public function testTheConsentUrlCarriesPkceTheCallbackAndTheWebhookPath(): void
    {
        $this->http->json(200, self::TOKEN);
        $connect = $this->app->make(\Varizet\Connect\OneClickConnect::class);
        $start = $connect->start([
            'client' => 'laravel',
            'site_url' => 'https://shop.test',
            'redirect_uri' => 'https://shop.test/varizet/connect/callback',
            'webhook_path' => '/varizet/webhook',
        ]);
        parse_str((string) parse_url($start->url, PHP_URL_QUERY), $q);
        self::assertSame(Pkce::challenge($start->verifier), $q['code_challenge']);
        self::assertSame('/varizet/webhook', $q['webhook_path']);
        self::assertSame('https://shop.test/varizet/connect/callback', $q['redirect_uri']);

        // the callback with that flow stored exchanges the code without an API key
        $this->app->make(\Varizet\Laravel\Connect\PendingConnects::class)->put($start->flow(), 'https://shop.test');
        $this->get('/varizet/connect/callback?code=vzc_ok&state=' . $start->state)->assertOk()->assertSee('اتصال برقرار شد');
        $exchange = $this->http->requests[0];
        self::assertSame('https://api.varizet.test/v1/integrations/connect/token', $exchange->url);
        self::assertArrayNotHasKey('Authorization', $exchange->headers);
        self::assertStringStartsWith('laravel/', $exchange->headers['Varizet-Plugin']);
        self::assertSame('https://shop.test', json_decode((string) $exchange->body, true)['site_url']);
        $result = $this->app->make(\Varizet\Laravel\Connect\PendingConnects::class)->result($start->state);
        self::assertSame(true, $result['ok'] ?? null);
        // single use
        $this->get('/varizet/connect/callback?code=vzc_ok&state=' . $start->state)->assertStatus(400);
    }

    public function testWritesEnvOnRequestAndReportsACancel(): void
    {
        $env = $this->app->environmentFilePath();
        file_put_contents($env, "APP_NAME=Shop\nVARIZET_API_KEY=old\n");
        try {
            $this->http->json(200, self::TOKEN);
            $this->approveWith(['code' => 'vzc_ok']);
            $this->artisan('varizet:connect', ['--write-env' => true, '--timeout' => 30])
                ->expectsOutputToContain('VARIZET_API_KEY و VARIZET_WEBHOOK_SECRET در')
                ->doesntExpectOutputToContain('VARIZET_API_KEY=vz_test_')
                ->assertSuccessful();
            $content = (string) file_get_contents($env);
            self::assertStringContainsString("APP_NAME=Shop\nVARIZET_API_KEY=vz_test_laravelConnected000000000000\n", $content);
            self::assertStringContainsString('VARIZET_WEBHOOK_SECRET=whsec_laravelConnected0000000000000', $content);
        } finally {
            @unlink($env);
        }

        $this->approveWith(['error' => 'access_denied']);
        $this->artisan('varizet:connect', ['--timeout' => 30])
            ->expectsOutputToContain('اتصال در پنل واریزت لغو شد.')
            ->assertFailed();
    }
}
