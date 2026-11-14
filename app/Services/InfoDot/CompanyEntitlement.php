<?php

namespace App\Services\InfoDot;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Asks InfoDot whether a company still holds this platform (R-50).
 *
 * InfoDot decides; this class only reads its answer for one company, by the company's InfoDot uuid, and keeps
 * it for ten minutes. Three answers are possible: true, false, or null for "could not find out". Null covers
 * every failure (no secret, timeout, refusal, a body that does not carry this platform's own row) and is
 * never treated as a refusal or shown as "active": an outage or a wrong setting at InfoDot must not lock a
 * paying company out. A failure is kept for a minute and logged once in that minute.
 */
class CompanyEntitlement
{
    private const HELD_FOR = 600;

    private const FAILURE_HELD_FOR = 60;

    public function enabled(): bool
    {
        return (bool) config('ecosystem.infodot.entitlement_check', true);
    }

    /** This platform's key in InfoDot's registry. */
    public function platform(): string
    {
        return (string) config('ecosystem.infodot.platform', 'projects');
    }

    /** Where a person manages their company's platforms in InfoDot. */
    public function manageUrl(): string
    {
        return rtrim((string) config('ecosystem.platforms.infodot.url', 'https://infodot.co.za'), '/').'/platforms/'.rawurlencode($this->platform());
    }

    /**
     * @return array{entitled: bool, status: ?string}|null null when InfoDot's answer is not known
     */
    public function for(string $teamUuid): ?array
    {
        $key = 'infodot:entitlement:'.$this->platform().':'.$teamUuid;

        try {
            $held = Cache::get($key);

            if (! is_array($held)) {
                $answer = $this->ask($teamUuid);
                $held = $answer ?? ['unknown' => true];
                Cache::put($key, $held, $answer === null ? self::FAILURE_HELD_FOR : self::HELD_FOR);
            }
        } catch (Throwable $e) {
            Log::warning('Platform entitlement could not be read; carrying on.', ['reason' => class_basename($e)]);

            return null;
        }

        return isset($held['entitled']) && is_bool($held['entitled']) ? ['entitled' => $held['entitled'], 'status' => $held['status'] ?? null] : null;
    }

    /**
     * One request to InfoDot. Every way it can fail is logged here (once per failure window, because the
     * caller keeps the failure) and comes back as null.
     *
     * @return array{entitled: bool, status: ?string}|null
     */
    private function ask(string $teamUuid): ?array
    {
        $secret = config('ecosystem.infodot.secret');
        $url = $this->readUrl($teamUuid);

        if (blank($secret) || $url === null) {
            return $this->unknown('not configured');
        }

        try {
            $response = Http::withToken((string) $secret)
                ->withHeaders(['X-Dot-Platform' => $this->platform()])
                ->acceptJson()
                ->timeout((int) config('ecosystem.infodot.timeout', 5))
                ->get($url, ['platform' => $this->platform()]);
        } catch (Throwable $e) {
            return $this->unknown('unreachable', ['error' => class_basename($e)]);
        }

        if (! $response->successful()) {
            return $this->unknown('refused or failed', ['status' => $response->status()]);
        }

        $body = $response->json();
        $own = is_array($body) && is_array($body['platforms'] ?? null)
            ? collect($body['platforms'])->first(fn ($row) => is_array($row) && ($row['key'] ?? null) === $this->platform())
            : null;

        if (! is_array($own) || ! is_bool($own['entitled'] ?? null)) {
            return $this->unknown('answer did not carry this platform');
        }

        return ['entitled' => $own['entitled'], 'status' => is_string($own['status'] ?? null) ? $own['status'] : null];
    }

    /** The read endpoint sits beside the redeem endpoint this app already calls. */
    private function readUrl(string $teamUuid): ?string
    {
        $redeem = (string) config('ecosystem.infodot.redeem_url', '');

        if (! Str::endsWith($redeem, '/redeem')) {
            return null;
        }

        return Str::beforeLast($redeem, '/redeem').'/companies/'.rawurlencode($teamUuid).'/platforms';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function unknown(string $reason, array $context = []): null
    {
        Log::warning('Platform entitlement could not be read from InfoDot; carrying on.', ['reason' => $reason] + $context);

        return null;
    }
}
