<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests\Feature;

use Illuminate\Support\Facades\Event as EventBus;
use PHPUnit\Framework\Attributes\DataProvider;
use Varizet\Laravel\Events\OrderCanceled;
use Varizet\Laravel\Events\OrderExpired;
use Varizet\Laravel\Events\OrderNeedsReview;
use Varizet\Laravel\Events\OrderPaid;
use Varizet\Laravel\Events\OrderUnderpaid;
use Varizet\Laravel\Events\RefundCreated;
use Varizet\Laravel\Events\WebhookReceived;
use Varizet\Laravel\Tests\TestCase;
use Varizet\Webhook\Signature;

final class WebhookTest extends TestCase
{
    private function body(string $type, array $object, bool $livemode = false): string
    {
        return (string) json_encode([
            'id' => 'evt_01JBX5ABCDEFGH',
            'type' => $type,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'livemode' => $livemode,
            'data' => ['object' => $object],
        ], JSON_UNESCAPED_UNICODE);
    }

    private function deliver(string $body, ?string $header): \Illuminate\Testing\TestResponse
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        if ($header !== null) {
            $server['HTTP_VARIZET_SIGNATURE'] = $header;
        }
        return $this->call('POST', '/varizet/webhook', [], [], [], $server, $body);
    }

    private function sign(string $body, array $secrets = [self::SECRET], ?int $t = null): string
    {
        return Signature::buildHeader($secrets, $t ?? time(), $body);
    }

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function types(): array
    {
        return [
            'paid' => ['order.paid', OrderPaid::class],
            'needs review' => ['order.needs_review', OrderNeedsReview::class],
            'underpaid' => ['order.underpaid', OrderUnderpaid::class],
            'expired' => ['order.expired', OrderExpired::class],
            'canceled' => ['order.canceled', OrderCanceled::class],
        ];
    }

    /** @param class-string $class */
    #[DataProvider('types')]
    public function testOrderEventsAreDispatched(string $type, string $class): void
    {
        EventBus::fake();
        $status = substr($type, 6) === 'paid' ? 'paid' : substr($type, 6);
        $body = $this->body($type, self::order(['status' => $status]));
        $this->deliver($body, $this->sign($body))->assertOk()->assertJson(['received' => true, 'type' => $type]);

        EventBus::assertDispatched(WebhookReceived::class, fn (WebhookReceived $e): bool => $e->event->type === $type);
        EventBus::assertDispatched($class, fn ($e): bool => $e->order->id === 'ord_01JBX4Q8M6' && $e->order->uniqueAmount === 8500430 && $e->event->id === 'evt_01JBX5ABCDEFGH');
        foreach (self::types() as [, $other]) {
            if ($other !== $class) {
                EventBus::assertNotDispatched($other);
            }
        }
    }

    public function testRefundCreated(): void
    {
        EventBus::fake();
        $body = $this->body('refund.created', ['id' => 'ref_01J', 'order_id' => 'ord_01JBX4Q8M6', 'amount' => 8500430, 'created_at' => '2026-10-28T12:00:00Z']);
        $this->deliver($body, $this->sign($body))->assertOk();
        EventBus::assertDispatched(RefundCreated::class, fn (RefundCreated $e): bool => $e->refund['amount'] === 8500430);
        EventBus::assertNotDispatched(OrderPaid::class);
    }

    public function testPingAndOtherTypesOnlyFireWebhookReceived(): void
    {
        EventBus::fake();
        foreach (['webhook.ping' => ['id' => 'whe_01J', 'object' => 'webhook_endpoint'], 'device.offline' => ['id' => 'dev_01J', 'status' => 'offline']] as $type => $object) {
            $body = $this->body($type, $object);
            $this->deliver($body, $this->sign($body))->assertOk();
        }
        EventBus::assertDispatchedTimes(WebhookReceived::class, 2);
        EventBus::assertNotDispatched(OrderPaid::class);
    }

    public function testRealDispatchReachesListeners(): void
    {
        $seen = [];
        EventBus::listen(OrderPaid::class, function (OrderPaid $e) use (&$seen): void {
            $seen[] = $e->order->code;
        });
        $body = $this->body('order.paid', self::order(['status' => 'paid', 'paid_amount' => 8500430]));
        $this->deliver($body, $this->sign($body))->assertOk();
        self::assertSame(['VZ-8K2Q'], $seen);
    }

    public function testAListenerFailureAsksVarizetToRetry(): void
    {
        EventBus::listen(OrderPaid::class, function (): void {
            throw new \RuntimeException('database down');
        });
        $body = $this->body('order.paid', self::order(['status' => 'paid']));
        // Laravel's handler answers 500, which Varizet retries with backoff.
        $this->deliver($body, $this->sign($body))->assertStatus(500);
    }

    public function testRotationAcceptsThePreviousSecret(): void
    {
        $this->app['config']->set('varizet.webhook.secret', 'whsec_new');
        $this->app['config']->set('varizet.webhook.previous_secret', self::SECRET);
        $body = $this->body('order.paid', self::order(['status' => 'paid']));
        $this->deliver($body, $this->sign($body, ['whsec_other', self::SECRET]))->assertOk();
    }

    public function testRejections(): void
    {
        EventBus::fake();
        $body = $this->body('order.paid', self::order(['status' => 'paid']));
        $this->deliver($body, null)->assertStatus(401)->assertJson(['error' => 'invalid_signature', 'reason' => 'malformed']);
        $this->deliver($body, $this->sign($body, ['whsec_wrong']))->assertStatus(401)->assertJson(['reason' => 'mismatch']);
        $this->deliver($body, $this->sign($body, [self::SECRET], time() - 301))->assertStatus(401)->assertJson(['reason' => 'expired']);
        $this->deliver($body . ' ', $this->sign($body))->assertStatus(401);

        $garbage = '{"hello":"world"}';
        $this->deliver($garbage, $this->sign($garbage))->assertStatus(400)->assertJson(['error' => 'invalid_payload']);
        $broken = $this->body('order.paid', ['id' => 'ord_1', 'status' => 'paid', 'amount' => '8500000']);
        $this->deliver($broken, $this->sign($broken))->assertStatus(400);

        EventBus::assertNotDispatched(WebhookReceived::class);
    }

    public function testNotConfiguredAnswers500SoDeliveriesAreRetried(): void
    {
        $this->app['config']->set('varizet.webhook.secret', null);
        $body = $this->body('order.paid', self::order());
        $this->deliver($body, $this->sign($body))->assertStatus(500)->assertJson(['error' => 'not_configured']);
    }

    public function testRouteIsOutsideTheWebGroupAndNamed(): void
    {
        $route = app('router')->getRoutes()->getByName('varizet.webhook');
        self::assertNotNull($route);
        self::assertSame(['POST'], $route->methods());
        self::assertSame(['varizet.signature'], $route->gatherMiddleware());
        self::assertSame('https://shop.test/varizet/webhook', route('varizet.webhook'));
    }
}
