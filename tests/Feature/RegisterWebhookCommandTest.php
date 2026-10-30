<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests\Feature;

use Varizet\Laravel\Tests\TestCase;

final class RegisterWebhookCommandTest extends TestCase
{
    private const PING_OK = ['id' => 'whe_01JLARAVEL', 'event_id' => 'evt_01JPING', 'ok' => true, 'status_code' => 200, 'latency_ms' => 42, 'error' => null];

    public function testCreatesTheEndpointPrintsTheSecretOnceAndPings(): void
    {
        $this->http->json(200, ['data' => [self::endpoint(['id' => 'whe_OTHER', 'url' => 'https://other.test/hook'])], 'has_more' => false, 'next_cursor' => null])
            ->json(201, self::endpoint() + ['secret' => 'whsec_created0000000000000000000000'])
            ->json(200, self::PING_OK);

        $this->artisan('varizet:webhook:register')
            ->expectsOutputToContain('endpoint whe_01JLARAVEL در حالت Test ساخته شد')
            ->expectsOutput('VARIZET_WEBHOOK_SECRET=whsec_created0000000000000000000000')
            ->expectsOutputToContain('پینگ موفق بود (HTTP 200')
            ->assertSuccessful();

        [$list, $create, $ping] = $this->http->requests;
        self::assertSame('GET', $list->method);
        self::assertStringStartsWith('https://api.varizet.test/v1/webhook_endpoints?limit=100', $list->url);
        $body = json_decode((string) $create->body, true);
        self::assertSame('https://shop.test/varizet/webhook', $body['url']);
        self::assertSame(config('varizet.webhook.events'), $body['events']);
        self::assertArrayHasKey('Idempotency-Key', $create->headers);
        self::assertSame('https://api.varizet.test/v1/webhook_endpoints/whe_01JLARAVEL/ping', $ping->url);
    }

    public function testReusesAnExistingEndpointAndRotatesOnRequest(): void
    {
        $this->http->json(200, ['data' => [self::endpoint(['url' => 'https://shop.test/varizet/webhook/'])], 'has_more' => false, 'next_cursor' => null])
            ->json(200, ['id' => 'whe_01JLARAVEL', 'secret' => 'whsec_rotated000000000000000000000', 'old_expires_at' => '2026-10-30T10:00:00Z']);

        $this->artisan('varizet:webhook:register', ['--rotate' => true, '--no-ping' => true])
            ->expectsOutputToContain('این آدرس قبلاً ثبت شده است')
            ->expectsOutputToContain('2026-10-30 10:00')
            ->expectsOutput('VARIZET_WEBHOOK_SECRET=whsec_rotated000000000000000000000')
            ->assertSuccessful();
        self::assertCount(2, $this->http->requests);
        self::assertStringEndsWith('/rotate_secret', $this->http->requests[1]->url);
    }

    public function testReEnablesADisabledEndpointWithoutPrintingASecret(): void
    {
        $this->http->json(200, ['data' => [self::endpoint(['status' => 'disabled', 'events' => ['order.paid']])], 'has_more' => false, 'next_cursor' => null])
            ->json(200, self::endpoint())
            ->json(200, ['ok' => false, 'status_code' => null, 'error' => 'connect ECONNREFUSED'] + self::PING_OK);

        $this->artisan('varizet:webhook:register', ['--url' => 'https://shop.test/varizet/webhook'])
            ->expectsOutputToContain('رویدادها و وضعیت endpoint به‌روز شد')
            ->expectsOutputToContain('--rotate')
            ->expectsOutputToContain('پینگ ناموفق بود (connect ECONNREFUSED)')
            ->doesntExpectOutputToContain('VARIZET_WEBHOOK_SECRET=')
            ->assertSuccessful();
        self::assertSame('PATCH', $this->http->requests[1]->method);
        self::assertSame(['events' => config('varizet.webhook.events'), 'status' => 'enabled'], json_decode((string) $this->http->requests[1]->body, true));
    }

    public function testWritesTheSecretToEnv(): void
    {
        $env = $this->app->environmentFilePath();
        $backup = is_file($env) ? file_get_contents($env) : null;
        file_put_contents($env, "APP_NAME=Shop\nVARIZET_WEBHOOK_SECRET=old\n");
        try {
            $this->http->json(200, ['data' => [], 'has_more' => false, 'next_cursor' => null])
                ->json(201, self::endpoint() + ['secret' => 'whsec_env00000000000000000000000000']);
            $this->artisan('varizet:webhook:register', ['--write-env' => true, '--no-ping' => true])
                ->doesntExpectOutputToContain('whsec_env')
                ->assertSuccessful();
            self::assertSame("APP_NAME=Shop\nVARIZET_WEBHOOK_SECRET=whsec_env00000000000000000000000000\n", file_get_contents($env));
        } finally {
            $backup === null ? @unlink($env) : file_put_contents($env, $backup);
        }
    }

    public function testExplainsAMissingScope(): void
    {
        $this->http->json(403, ['error' => ['type' => 'permission_error', 'code' => 'scope_missing', 'message' => 'این کلید دسترسی «webhooks:manage» را ندارد.', 'param' => null, 'request_id' => 'req_1']]);
        $this->artisan('varizet:webhook:register')
            ->expectsOutputToContain('webhooks:manage ندارد')
            ->assertFailed();
    }

    public function testRejectsARelativeUrl(): void
    {
        $this->artisan('varizet:webhook:register', ['--url' => '/varizet/webhook'])->assertFailed();
        self::assertSame([], $this->http->requests);
    }
}
