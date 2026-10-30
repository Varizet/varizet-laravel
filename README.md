# varizet/laravel

Laravel 10, 11 and 12 integration for Varizet («واریزت»), on top of
[`varizet/varizet-php`](https://github.com/Varizet/varizet-php): auto-discovered service provider, `Varizet` facade,
publishable config, a signed webhook route that dispatches Laravel events, and an artisan command
that registers the webhook endpoint.

```bash
composer require varizet/laravel
php artisan vendor:publish --tag=varizet-config      # optional
```

```dotenv
VARIZET_API_KEY=vz_test_…
VARIZET_WEBHOOK_SECRET=whsec_…            # from the panel, or the command below
# VARIZET_WEBHOOK_PREVIOUS_SECRET=whsec_… # for 24 h after a rotation
# VARIZET_API_BASE_URL=http://localhost:4000
```

## Create an order and redirect

```php
use Varizet\Laravel\Facades\Varizet;
use Varizet\Util\IdempotencyKey;

$order = Varizet::orders()->create([
    'amount' => $invoice->total_rial,                    // integer rial, ≥ 10 000
    'merchant_order_id' => (string) $invoice->number,
    'customer' => ['name' => $user->name, 'phone' => $user->mobile],
    'return_url' => route('checkout.return', $invoice),
    'metadata' => ['invoice_id' => (string) $invoice->id],
], IdempotencyKey::forAttempt('laravel', $invoice->id, $invoice->payment_attempt));

$invoice->update(['varizet_order_id' => $order->id]);
return redirect()->away($order->paymentUrl);
```

Or inject `Varizet\VarizetClient` (a singleton built from the config; it sends
`Varizet-Plugin: laravel/1.0.0; laravel=12.x; sdk=1.0.0; php=8.x`). Bind your own
`Varizet\Http\HttpClientInterface` in the container to change the transport (e.g.
`Varizet\Http\Psr18HttpClient` around Guzzle).

## Webhooks

The provider registers `POST /varizet/webhook` (name `varizet.webhook`; path, extra middleware
and on/off in `config/varizet.php`). It is outside the `web` group, so there is no CSRF or
session; the `varizet.signature` middleware verifies `Varizet-Signature` on the raw body with the
current and previous secret and a 300 s window (401 on failure, 400 for a signed non-event, 500
while no secret is configured so Varizet retries later).

| Webhook type | Laravel event | Payload |
| --- | --- | --- |
| every verified event (also `webhook.ping`) | `Varizet\Laravel\Events\WebhookReceived` | `$event` (`Varizet\Model\Event`) |
| `order.paid` | `OrderPaid` | `$event`, `$order` (`Varizet\Model\Order`) |
| `order.needs_review` | `OrderNeedsReview` | `$event`, `$order` |
| `order.underpaid` | `OrderUnderpaid` | `$event`, `$order` |
| `order.expired` | `OrderExpired` | `$event`, `$order` |
| `order.canceled` | `OrderCanceled` | `$event`, `$order` |
| `refund.created` | `RefundCreated` | `$event`, `$refund` (raw object) |

```php
use Varizet\Laravel\Events\OrderPaid;
use Varizet\Model\OrderStatus;

Event::listen(function (OrderPaid $e) {
    $invoice = Invoice::where('varizet_order_id', $e->order->id)->lockForUpdate()->first();
    if (!$invoice || $invoice->processed_event_ids->contains($e->event->id)) {
        return;                                          // other integration, or a re-delivery
    }
    if (OrderStatus::isRegression($invoice->varizet_status, $e->order->status)) {
        return;                                          // late event: never undo a payment
    }
    $invoice->markPaid($e->order->paidAmount, $e->order->code);
});
```

Delivery is at least once and unordered: dedupe on `$e->event->id` and compare with your stored
state. A listener that throws makes the route answer 500, and Varizet retries (10 s … 24 h, 8
attempts); queue slow work (`ShouldQueue`), since Varizet waits at most 10 s. Keep a polling
fallback for open orders (`Varizet::orders()->retrieveMany($ids)` from the scheduler) in case your
endpoint is down for longer.

## Connect in one click

```bash
php artisan varizet:connect            # prints the consent URL, waits for the approval
php artisan varizet:connect --write-env   # …and writes VARIZET_API_KEY + VARIZET_WEBHOOK_SECRET to .env
```

The command builds the Varizet consent URL (PKCE, `client=laravel`, `site=APP_URL` or `--site`,
`webhook_path` = `config('varizet.webhook.path')`) and waits up to `--timeout` seconds. After the
merchant picks the store and Live/Test, the browser returns to `GET /varizet/connect/callback`
(`varizet.connect.callback`, `config('varizet.connect')`), which exchanges the code and leaves the
key and webhook secret, encrypted, in the application cache for the command (so the cache must be
shared: file, redis or database, not `array`). Varizet creates and enables the webhook endpoint
(order events + `refund.created`). Set `VARIZET_APP_URL` for the dev panel
(`http://app.varizet.local:8080`); `VARIZET_CONNECT_ROUTE=false` removes the route.

## Register the endpoint

```bash
php artisan varizet:webhook:register                 # URL = route('varizet.webhook') from APP_URL
php artisan varizet:webhook:register --url=https://shop.ir/varizet/webhook --write-env
php artisan varizet:webhook:register --rotate        # new secret for an existing endpoint
```

It reuses an endpoint with the same URL (re-enabling it and syncing the events), creates one
otherwise, shows the signing secret once (or writes `VARIZET_WEBHOOK_SECRET` to `.env` with
`--write-env`), and sends a signed `webhook.ping`. The key needs the `webhooks:manage` scope (keys
made by the WordPress connect flow only have `orders:*`); the command says so when it is missing.
`--event=order.paid --event=order.expired` overrides `config('varizet.webhook.events')`.

## Development

```bash
composer update                 # the newest Laravel your PHP allows
vendor/bin/phpunit
# without a local PHP:
# docker run --rm -v "$PWD":/app -w /app composer:2 sh -c 'composer update && vendor/bin/phpunit'
```

CI runs Laravel 10 on PHP 8.1, 11 on PHP 8.2 and 12 on PHP 8.3. Inside the Varizet monorepo the SDK
comes from `../sdk-php` through a Composer path repository and `bin/test-matrix.sh` runs the same
legs in Docker; the standalone repository pulls `varizet/varizet-php` from GitHub. The Laravel 10 and
11 legs disable Composer's advisory block: both majors are past their security support and every
release has open advisories, so those legs only prove compatibility; apps on them should upgrade.

---

## Contributing and security

Issues and pull requests are welcome. This repository is published from Varizet's main repository:
accepted changes are applied there and appear here with the next release.

Please report security vulnerabilities privately to support@varizet.ir, not in public issues.

## License

MIT, see [LICENSE](LICENSE).
