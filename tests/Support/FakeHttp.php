<?php

declare(strict_types=1);

namespace Varizet\Laravel\Tests\Support;

use Varizet\Http\HttpClientInterface;
use Varizet\Http\HttpRequest;
use Varizet\Http\HttpResponse;

/** Answers queued JSON responses and records requests. */
final class FakeHttp implements HttpClientInterface
{
    /** @var list<HttpRequest> */
    public array $requests = [];

    /** @var list<HttpResponse> */
    private array $queue = [];

    public function json(int $status, array $body): self
    {
        $this->queue[] = new HttpResponse($status, ['content-type' => 'application/json', 'x-request-id' => 'req_FAKE'], (string) json_encode($body, JSON_UNESCAPED_UNICODE));
        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;
        return array_shift($this->queue) ?? throw new \LogicException('unexpected ' . $request->method . ' ' . $request->url);
    }
}
