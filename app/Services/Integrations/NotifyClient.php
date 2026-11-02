<?php

namespace App\Services\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Sends one event to Dot.Notify (POST /api/ingest/events): "this happened to this company, tell
 * this person". The scheme is Notify's own, as Dot.HR uses it: HMAC-SHA256 over
 * "{timestamp}.{raw body}" with this platform's secret, sent as "sha256=<hex>" in
 * X-InfoDot-Signature with the unix time in X-InfoDot-Timestamp, made at send time so a retry is
 * never stale. A small circuit breaker stops calling a failing Notify for a minute. Never throws.
 */
final class NotifyClient
{
    private const FAILURE_THRESHOLD = 5;

    private const PAUSE_SECONDS = 60;

    private const KEY_FAILURES = 'integrations:notify:failures';

    private const KEY_OPEN_UNTIL = 'integrations:notify:open-until';

    public function enabled(): bool
    {
        return filled(config('services.notify.url')) && filled(config('services.notify.secret'));
    }

    /**
     * Posts one payload and says what the outbox should do next, with the HTTP status and a reason
     * a person can act on.
     *
     * @param  array<string, mixed>  $payload
     * @return array{0: string, 1: int|null, 2: string|null} [Outbox::SENT|RETRY|GIVE_UP, status, reason]
     */
    public function deliver(array $payload): array
    {
        if (! $this->enabled()) {
            return [Outbox::GIVE_UP, null, 'Dot.Notify is not set up on this server (NOTIFY_INGEST_URL and NOTIFY_INGEST_SECRET).'];
        }
        if ($this->circuitOpen()) {
            return [Outbox::RETRY, null, 'Dot.Notify failed several times in a row; pausing for a minute.'];
        }
        try {
            $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $timestamp = (string) now()->timestamp;
            $timeout = (int) config('services.notify.timeout', 5);
            $response = Http::timeout($timeout)->connectTimeout($timeout)->acceptJson()
                ->withHeaders([
                    'X-InfoDot-Timestamp' => $timestamp,
                    'X-InfoDot-Signature' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, (string) config('services.notify.secret')),
                ])
                ->withBody($body, 'application/json')
                ->post((string) config('services.notify.url'));
        } catch (ConnectionException) {
            $this->recordFailure();

            return [Outbox::RETRY, null, 'Dot.Notify could not be reached.'];
        } catch (Throwable $e) {
            $this->recordFailure();

            return [Outbox::RETRY, null, 'The call to Dot.Notify failed ('.class_basename($e).').'];
        }

        $status = $response->status();
        if ($status >= 200 && $status < 300) {
            $this->recordSuccess();

            return [Outbox::SENT, $status, null];
        }
        if ($status === 429 || $status >= 500) {
            $this->recordFailure();

            return [Outbox::RETRY, $status, $status === 429 ? 'Dot.Notify asked us to slow down.' : 'Dot.Notify had an error.'];
        }

        // Retrying cannot fix these, and they say nothing about Notify's health.
        return [Outbox::GIVE_UP, $status, match ($status) {
            401, 403 => 'Dot.Notify did not accept our signature: NOTIFY_INGEST_SECRET here must equal NOTIFY_INGEST_SECRET_PROJECTS on Notify.',
            404 => 'Dot.Notify does not know this business: it must be linked to InfoDot in both apps.',
            422 => 'Dot.Notify refused the event as invalid.',
            default => 'Dot.Notify answered '.$status.'.',
        }];
    }

    private function circuitOpen(): bool
    {
        try {
            return (int) Cache::get(self::KEY_OPEN_UNTIL, 0) > now()->timestamp;
        } catch (Throwable) {
            return false;
        }
    }

    private function recordFailure(): void
    {
        try {
            $failures = (int) Cache::get(self::KEY_FAILURES, 0) + 1;
            Cache::put(self::KEY_FAILURES, $failures, 300);
            if ($failures >= self::FAILURE_THRESHOLD) {
                Cache::put(self::KEY_OPEN_UNTIL, now()->timestamp + self::PAUSE_SECONDS, self::PAUSE_SECONDS);
            }
        } catch (Throwable) {
            // The breaker is a courtesy to Notify; losing it must not matter.
        }
    }

    private function recordSuccess(): void
    {
        try {
            Cache::forget(self::KEY_FAILURES);
            Cache::forget(self::KEY_OPEN_UNTIL);
        } catch (Throwable) {
        }
    }
}
