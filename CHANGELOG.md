# Changelog

## [1.1.0] - 2026-10-29

- `php artisan varizet:connect [--site] [--write-env] [--timeout]`: one-click connect. Prints the
  Varizet consent URL; after approval the browser returns to `GET /varizet/connect/callback`
  (named `varizet.connect.callback`, `config('varizet.connect')`), which exchanges the code; the
  command prints or writes `VARIZET_API_KEY` and `VARIZET_WEBHOOK_SECRET`. The webhook endpoint is
  created at `config('varizet.webhook.path')` with the order events and `refund.created`.
- Requires `varizet/varizet-php` 1.1 (one-click connect, refunds read API).

## [1.0.0] - 2026-10-29

- First release for Laravel 10, 11 and 12 (auto-discovered `VarizetServiceProvider`, `Varizet`
  facade, publishable `config/varizet.php`).
- `POST /varizet/webhook` (named `varizet.webhook`, outside the `web` group) behind the
  `varizet.signature` middleware: current + previous secret, ±300 s.
- Events: `WebhookReceived`, `OrderPaid`, `OrderNeedsReview`, `OrderUnderpaid`, `OrderExpired`,
  `OrderCanceled`, `RefundCreated`.
- `php artisan varizet:webhook:register [--url] [--event=*] [--rotate] [--write-env] [--no-ping]`.
