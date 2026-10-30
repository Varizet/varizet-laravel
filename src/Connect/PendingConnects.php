<?php

declare(strict_types=1);

namespace Varizet\Laravel\Connect;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Encryption\Encrypter;
use Varizet\Connect\OneClickConnect;

/**
 * Hands the one-click connect between `php artisan varizet:connect` (which starts it and waits) and
 * the callback route (which the browser hits after the merchant approves). Both sides share the
 * application cache (file, redis, database; not `array`). The PKCE flow and the result are stored
 * encrypted under the hash of the `state`, and each is read once.
 */
final class PendingConnects
{
    public function __construct(
        private readonly Repository $cache,
        private readonly Encrypter $encrypter,
    ) {
    }

    /** @param array{state: string, verifier: string, at: int} $flow */
    public function put(array $flow, string $siteUrl): void
    {
        $this->cache->put(
            $this->key('flow', $flow['state']),
            $this->encrypter->encrypt(['flow' => $flow, 'site' => $siteUrl]),
            OneClickConnect::FLOW_TTL,
        );
    }

    /** @return array{flow: array{state: string, verifier: string, at: int}, site: string}|null */
    public function pull(string $state): ?array
    {
        $stored = $this->cache->pull($this->key('flow', $state));
        if (!is_string($stored)) {
            return null;
        }
        $data = $this->encrypter->decrypt($stored);
        return is_array($data) && is_array($data['flow'] ?? null) && is_string($data['site'] ?? null) ? $data : null;
    }

    /** @param array<string, mixed> $result `ok`, and `api_key`/`webhook_secret`/`store`/`mode` or `error` */
    public function complete(string $state, array $result): void
    {
        $this->cache->put($this->key('result', $state), $this->encrypter->encrypt($result), OneClickConnect::FLOW_TTL);
    }

    /** @return array<string, mixed>|null */
    public function result(string $state): ?array
    {
        $stored = $this->cache->pull($this->key('result', $state));
        if (!is_string($stored)) {
            return null;
        }
        $data = $this->encrypter->decrypt($stored);
        return is_array($data) ? $data : null;
    }

    private function key(string $kind, string $state): string
    {
        return 'varizet:connect:' . $kind . ':' . hash('sha256', $state);
    }
}
