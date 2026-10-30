<?php

declare(strict_types=1);

namespace Varizet\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Varizet\Connect\OneClickConnect;
use Varizet\Exception\InvalidArgumentException;
use Varizet\Http\HttpClientInterface;
use Varizet\Laravel\Connect\PendingConnects;
use Varizet\Laravel\Console\ConnectCommand;
use Varizet\Laravel\Console\RegisterWebhookCommand;
use Varizet\Laravel\Http\Controllers\ConnectCallbackController;
use Varizet\Laravel\Http\Controllers\WebhookController;
use Varizet\Laravel\Http\Middleware\VerifyVarizetSignature;
use Varizet\VarizetClient;

final class VarizetServiceProvider extends ServiceProvider
{
    public const VERSION = '1.1.0';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/varizet.php', 'varizet');

        // Lazy: an app without a key can boot, run migrations and serve webhooks.
        $this->app->singleton(VarizetClient::class, static function (Application $app): VarizetClient {
            $config = $app['config']->get('varizet', []);
            $key = $config['api_key'] ?? null;
            if (!is_string($key) || $key === '') {
                throw new InvalidArgumentException('VARIZET_API_KEY is not set (config/varizet.php → api_key).');
            }
            $options = [
                'base_url' => (string) ($config['base_url'] ?? VarizetClient::DEFAULT_BASE_URL),
                'timeout' => max(1, (int) ($config['timeout'] ?? 15)),
                'max_retries' => max(0, (int) ($config['max_retries'] ?? 2)),
                'plugin' => [
                    'kind' => 'laravel',
                    'version' => self::VERSION,
                    'meta' => ['laravel' => $app->version()],
                ],
            ];
            // Tests (or apps wanting Guzzle via Http\Psr18HttpClient) bind their own transport.
            if ($app->bound(HttpClientInterface::class)) {
                $options['http_client'] = $app->make(HttpClientInterface::class);
            }
            return new VarizetClient($key, $options);
        });
        $this->app->alias(VarizetClient::class, 'varizet');

        // one-click connect: works before any key exists
        $this->app->singleton(OneClickConnect::class, static function (Application $app): OneClickConnect {
            $config = $app['config']->get('varizet', []);
            $options = [
                'app_url' => (string) ($config['connect']['app_url'] ?? OneClickConnect::DEFAULT_APP_URL),
                'base_url' => (string) ($config['base_url'] ?? VarizetClient::DEFAULT_BASE_URL),
                'plugin' => ['kind' => 'laravel', 'version' => self::VERSION, 'meta' => ['laravel' => $app->version()]],
            ];
            if ($app->bound(HttpClientInterface::class)) {
                $options['http_client'] = $app->make(HttpClientInterface::class);
            }
            return VarizetClient::oneClickConnect($options);
        });
        $this->app->singleton(
            PendingConnects::class,
            static fn (Application $app): PendingConnects => new PendingConnects($app->make('cache.store'), $app->make('encrypter')),
        );
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/varizet.php' => $this->app->configPath('varizet.php')], 'varizet-config');
            $this->commands([RegisterWebhookCommand::class, ConnectCommand::class]);
        }

        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('varizet.signature', VerifyVarizetSignature::class);

        if (filter_var($this->app['config']->get('varizet.webhook.route', true), FILTER_VALIDATE_BOOL)) {
            $middleware = array_merge(
                ['varizet.signature'],
                (array) $this->app['config']->get('varizet.webhook.middleware', []),
            );
            Route::post(
                trim((string) $this->app['config']->get('varizet.webhook.path', 'varizet/webhook'), '/'),
                WebhookController::class,
            )->middleware($middleware)->name('varizet.webhook');
        }

        if (filter_var($this->app['config']->get('varizet.connect.route', true), FILTER_VALIDATE_BOOL)) {
            Route::get(
                trim((string) $this->app['config']->get('varizet.connect.callback_path', 'varizet/connect/callback'), '/'),
                ConnectCallbackController::class,
            )->middleware((array) $this->app['config']->get('varizet.connect.middleware', []))->name('varizet.connect.callback');
        }
    }
}
