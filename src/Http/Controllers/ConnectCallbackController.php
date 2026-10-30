<?php

declare(strict_types=1);

namespace Varizet\Laravel\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Varizet\Connect\ConnectOutcome;
use Varizet\Connect\OneClickConnect;
use Varizet\Laravel\Connect\PendingConnects;

/**
 * `GET /varizet/connect/callback` (named `varizet.connect.callback`): the merchant approved (or
 * cancelled) in the Varizet panel. Exchanges the code with the flow `varizet:connect` stored and
 * leaves the key and secret for that command to pick up; the browser gets a short page.
 */
final class ConnectCallbackController
{
    public function __invoke(Request $request, PendingConnects $pending, OneClickConnect $connect): Response
    {
        $state = (string) $request->query('state', '');
        $stored = $state === '' ? null : $pending->pull($state);
        $outcome = $connect->finish($request->query(), $stored['flow'] ?? null, $stored['site'] ?? '');
        if ($state !== '' && $stored !== null) {
            $pending->complete($state, $outcome->ok() && $outcome->result !== null
                ? [
                    'ok' => true,
                    'api_key' => $outcome->result->apiKey,
                    'webhook_secret' => $outcome->result->webhookSecret,
                    'mode' => $outcome->result->mode(),
                    'store' => $outcome->result->storeName,
                ]
                : ['ok' => false, 'error' => $outcome->error]);
        }
        [$title, $message, $status] = match (true) {
            $outcome->ok() => ['اتصال برقرار شد', 'به ترمینال برگردید: کلید API و secret وب‌هوک آماده است.', 200],
            $outcome->error === ConnectOutcome::CANCELLED => ['اتصال لغو شد', 'اتصال در پنل واریزت لغو شد.', 200],
            $outcome->error === ConnectOutcome::EXCHANGE_FAILED => ['اتصال ناموفق بود', 'واریزت کد اتصال را نپذیرفت؛ php artisan varizet:connect را دوباره اجرا کنید.', 400],
            default => ['درخواست منقضی شده است', 'php artisan varizet:connect را دوباره اجرا کنید.', 400],
        };
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        return new Response(
            '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $e($title) . '</title></head><body style="font-family:Vazirmatn,Tahoma,sans-serif;padding:48px 16px;text-align:center">'
            . '<h1 style="font-size:18px">' . $e($title) . '</h1><p>' . $e($message) . '</p></body></html>',
            $status,
            ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'],
        );
    }
}
