<?php

declare(strict_types=1);

namespace Varizet\Laravel\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\URL;
use Varizet\Exception\ApiException;
use Varizet\Exception\ExceptionInterface;
use Varizet\Exception\PermissionException;
use Varizet\Model\WebhookEndpoint;
use Varizet\VarizetClient;

/**
 * Registers this app's webhook URL with Varizet (`POST /v1/webhook_endpoints`), prints the signing
 * secret once (or writes it to .env), and pings the endpoint. Idempotent: an existing endpoint
 * for the same URL is reused; `--rotate` gives it a new secret. Needs a `webhooks:manage` key.
 */
final class RegisterWebhookCommand extends Command
{
    protected $signature = 'varizet:webhook:register
        {--url= : Public URL of the webhook route (default: route("varizet.webhook") from APP_URL)}
        {--event=* : Event types to subscribe to (default: config varizet.webhook.events)}
        {--rotate : If the endpoint exists, rotate its secret (the old one signs for 24 more hours)}
        {--write-env : Write the secret to VARIZET_WEBHOOK_SECRET in .env instead of printing it}
        {--no-ping : Do not send the signed webhook.ping test}';

    protected $description = 'ثبت آدرس وب‌هوک این برنامه در واریزت و دریافت secret امضا';

    public function handle(VarizetClient $varizet): int
    {
        $url = (string) ($this->option('url') ?: URL::route('varizet.webhook'));
        if (!preg_match('#^https?://#i', $url)) {
            $this->error('آدرس وب‌هوک باید کامل باشد، مثلاً https://shop.ir/varizet/webhook');
            return self::FAILURE;
        }
        /** @var list<string> $events */
        $events = array_values(array_filter((array) ($this->option('event') ?: config('varizet.webhook.events', [])), 'is_string'));
        $mode = $varizet->isLivemode() ? 'Live' : 'Test';

        try {
            $existing = $this->find($varizet, $url);
            $secret = null;
            if ($existing !== null) {
                $this->info(sprintf('این آدرس قبلاً ثبت شده است (%s، %s، %s).', $existing->id, $mode, $existing->status));
                if ($existing->events !== $events || !$existing->isEnabled()) {
                    $existing = $varizet->webhookEndpoints->update($existing->id, ['events' => $events, 'status' => 'enabled']);
                    $this->line('رویدادها و وضعیت endpoint به‌روز شد.');
                }
                if ($this->option('rotate')) {
                    $rotated = $varizet->webhookEndpoints->rotateSecret($existing->id);
                    $secret = $rotated->secret;
                    $this->line(sprintf(
                        'secret جدید ساخته شد؛ secret قبلی تا %s UTC هم امضا می‌کند (در VARIZET_WEBHOOK_PREVIOUS_SECRET نگه دارید).',
                        $rotated->previousExpiresAt->format('Y-m-d H:i'),
                    ));
                } else {
                    $this->line('secret فقط هنگام ساخت نمایش داده می‌شود؛ اگر آن را ندارید با --rotate secret تازه بگیرید.');
                }
            } else {
                $existing = $varizet->webhookEndpoints->create([
                    'url' => $url,
                    'events' => $events,
                    'description' => 'Laravel: ' . (string) config('app.name', 'app'),
                ]);
                $secret = $existing->secret;
                $this->info(sprintf('endpoint %s در حالت %s ساخته شد: %s', $existing->id, $mode, $url));
            }

            if ($secret !== null) {
                $this->deliverSecret($secret);
            }

            if (!$this->option('no-ping')) {
                $ping = $varizet->webhookEndpoints->ping($existing->id);
                if ($ping->ok) {
                    $this->info(sprintf('پینگ موفق بود (HTTP %d، %d میلی‌ثانیه).', (int) $ping->statusCode, $ping->latencyMs));
                } else {
                    $this->warn(sprintf(
                        'پینگ ناموفق بود (%s). آدرس باید از اینترنت در دسترس باشد و secret در .env تنظیم شده باشد.',
                        $ping->statusCode !== null ? 'HTTP ' . $ping->statusCode : (string) $ping->error,
                    ));
                }
            }
        } catch (PermissionException $e) {
            $this->error('این کلید API دسترسی webhooks:manage ندارد. در پنل واریزت ← توسعه‌دهندگان کلیدی با این دسترسی بسازید.');
            $this->line($e->getMessage());
            return self::FAILURE;
        } catch (ApiException $e) {
            $this->error(sprintf('%s (%s، request_id: %s)', $e->getMessage(), $e->errorCode, (string) $e->requestId));
            return self::FAILURE;
        } catch (ExceptionInterface $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function find(VarizetClient $varizet, string $url): ?WebhookEndpoint
    {
        foreach ($varizet->webhookEndpoints->all(['limit' => 100]) as $endpoint) {
            if (rtrim($endpoint->url, '/') === rtrim($url, '/')) {
                return $endpoint;
            }
        }
        return null;
    }

    private function deliverSecret(string $secret): void
    {
        if (!$this->option('write-env')) {
            $this->warn('secret فقط همین یک‌بار نمایش داده می‌شود؛ آن را در .env بگذارید:');
            $this->line('VARIZET_WEBHOOK_SECRET=' . $secret);
            return;
        }
        $path = $this->laravel->environmentFilePath();
        $content = is_file($path) ? (string) file_get_contents($path) : '';
        $line = 'VARIZET_WEBHOOK_SECRET=' . $secret;
        $content = preg_match('/^VARIZET_WEBHOOK_SECRET=.*$/m', $content) === 1
            ? (string) preg_replace_callback('/^VARIZET_WEBHOOK_SECRET=.*$/m', static fn (): string => $line, $content)
            : rtrim($content, "\n") . ($content === '' ? '' : "\n") . $line . "\n";
        file_put_contents($path, $content);
        $this->info(sprintf('secret در %s ذخیره شد (VARIZET_WEBHOOK_SECRET). اگر config کش شده، php artisan config:clear بزنید.', $path));
    }
}
