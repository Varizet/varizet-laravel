<?php

declare(strict_types=1);

namespace Varizet\Laravel\Console;

use Illuminate\Console\Command;
use Varizet\Connect\OneClickConnect;
use Varizet\Laravel\Connect\PendingConnects;
use Varizet\Laravel\VarizetServiceProvider;

/**
 * `php artisan varizet:connect`: one-click connect (docs/10 §1.4). Prints the Varizet consent URL;
 * after the merchant approves in the browser, the package's callback route exchanges the code and
 * this command prints (or, with --write-env, writes) VARIZET_API_KEY and VARIZET_WEBHOOK_SECRET.
 * The site must be reachable at APP_URL (or --site) and share the cache with the CLI. Manual keys
 * in .env keep working.
 */
final class ConnectCommand extends Command
{
    use WritesEnv;

    protected $signature = 'varizet:connect
        {--site= : The site URL Varizet connects (default: APP_URL)}
        {--write-env : Write VARIZET_API_KEY and VARIZET_WEBHOOK_SECRET to .env instead of printing them}
        {--timeout=600 : Seconds to wait for the approval}';

    protected $description = 'Connect this app to Varizet in one click (API key + webhook secret)';

    public function handle(OneClickConnect $connect, PendingConnects $pending): int
    {
        if (!filter_var(config('varizet.connect.route', true), FILTER_VALIDATE_BOOL)) {
            $this->error('مسیر بازگشت اتصال غیرفعال است (VARIZET_CONNECT_ROUTE=false)؛ کلید را دستی در .env بگذارید.');
            return self::FAILURE;
        }
        $site = rtrim((string) ($this->option('site') ?: config('app.url')), '/');
        if (!in_array(parse_url($site, PHP_URL_SCHEME), ['https', 'http'], true)) {
            $this->error('آدرس سایت معتبر نیست؛ APP_URL را تنظیم کنید یا --site=https://… بدهید.');
            return self::FAILURE;
        }
        $callback = $site . '/' . trim((string) config('varizet.connect.callback_path', 'varizet/connect/callback'), '/');
        $start = $connect->start([
            'client' => 'laravel',
            'site_url' => $site,
            'redirect_uri' => $callback,
            'plugin_version' => VarizetServiceProvider::VERSION,
            'webhook_path' => '/' . trim((string) config('varizet.webhook.path', 'varizet/webhook'), '/'),
        ]);
        $pending->put($start->flow(), $site);

        $this->info('این آدرس را در مرورگر باز کنید، فروشگاه و حالت Live یا Test را انتخاب و تایید کنید:');
        $this->line($start->url);
        $this->line('در انتظار تایید… (خروج: Ctrl+C)');

        $deadline = time() + max(10, (int) $this->option('timeout'));
        while (time() < $deadline) {
            $result = $pending->result($start->state);
            if ($result !== null) {
                return $this->finish($result);
            }
            $this->wait($start->state);
        }
        $this->error('مهلت تایید تمام شد؛ دوباره php artisan varizet:connect را اجرا کنید.');
        return self::FAILURE;
    }

    /** @param array<string, mixed> $result */
    private function finish(array $result): int
    {
        if (($result['ok'] ?? false) !== true) {
            $this->error(match ($result['error'] ?? null) {
                'cancelled' => 'اتصال در پنل واریزت لغو شد.',
                'exchange_failed' => 'واریزت کد اتصال را نپذیرفت؛ دوباره تلاش کنید.',
                default => 'درخواست اتصال منقضی شد؛ دوباره تلاش کنید.',
            });
            return self::FAILURE;
        }
        $mode = ($result['mode'] ?? 'test') === 'live' ? 'Live' : 'Test';
        $this->info(sprintf('اتصال برقرار شد: فروشگاه «%s»، حالت %s.', (string) ($result['store'] ?? ''), $mode));
        $values = [
            'VARIZET_API_KEY' => (string) $result['api_key'],
            'VARIZET_WEBHOOK_SECRET' => (string) $result['webhook_secret'],
        ];
        if ($this->option('write-env')) {
            $this->writeEnv($values);
            return self::SUCCESS;
        }
        $this->warn('این مقادیر فقط همین یک‌بار نمایش داده می‌شوند؛ آن‌ها را در .env بگذارید:');
        foreach ($values as $name => $value) {
            $this->line($name . '=' . $value);
        }
        return self::SUCCESS;
    }

    /** Two seconds between checks; tests bind `varizet.connect.wait` (it gets the pending state). */
    private function wait(string $state): void
    {
        $sleep = $this->laravel->bound('varizet.connect.wait') ? $this->laravel->make('varizet.connect.wait') : null;
        is_callable($sleep) ? $sleep($state) : usleep(2_000_000);
    }
}
