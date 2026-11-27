<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Short-lived TURN credentials from Cloudflare Realtime ($0.05 per GB relayed), for
 * servers that cannot run their own coturn, such as shared hosting.
 *
 * Set `SMACKCHAT_CALL_CLOUDFLARE_TURN_KEY_ID` and
 * `SMACKCHAT_CALL_CLOUDFLARE_TURN_TOKEN` (Cloudflare dashboard → Realtime →
 * TURN Server → create key). The credentials live for a day and are reused
 * for half of it, so a workspace loaded this morning still relays tonight.
 */
class CloudflareTurn
{
    public const TTL_SECONDS = 86400;

    private const CACHE_KEY = 'calls:cloudflare-turn';

    /**
     * @return list<array<string, mixed>> RTCIceServer entries; empty when not set up or unreachable
     */
    public function iceServers(): array
    {
        $keyId = config('smackchat.call_cloudflare_turn_key_id');
        $token = config('smackchat.call_cloudflare_turn_token');

        if (! filled($keyId) || ! filled($token)) {
            return [];
        }

        $cached = Cache::get(self::CACHE_KEY.':'.$keyId);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::withToken((string) $token)
                ->timeout(5)
                ->post('https://rtc.live.cloudflare.com/v1/turn/keys/'.rawurlencode((string) $keyId).'/credentials/generate-ice-servers', [
                    'ttl' => self::TTL_SECONDS,
                ]);
        } catch (Throwable $exception) {
            Log::warning('Cloudflare TURN credentials could not be fetched.', ['error' => $exception->getMessage()]);

            return [];
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare TURN refused the key.', ['status' => $response->status()]);

            return [];
        }

        $servers = $this->normalize($response->json('iceServers'));

        if ($servers !== []) {
            Cache::put(self::CACHE_KEY.':'.$keyId, $servers, intdiv(self::TTL_SECONDS, 2));
        }

        return $servers;
    }

    /**
     * Accept a list or a single entry, and drop port 53 URLs: browsers block
     * them, and a blocked URL only slows down gathering.
     *
     * @return list<array<string, mixed>>
     */
    private function normalize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $entries = array_key_exists('urls', $raw) ? [$raw] : $raw;
        $servers = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['urls'])) {
                continue;
            }

            $urls = array_values(array_filter(
                array_map('strval', (array) $entry['urls']),
                fn (string $url) => ! preg_match('/:53(\?|$)/', $url),
            ));

            if ($urls === []) {
                continue;
            }

            $servers[] = array_filter([
                'urls' => $urls,
                'username' => $entry['username'] ?? null,
                'credential' => $entry['credential'] ?? null,
            ], fn ($value) => filled($value));
        }

        return $servers;
    }
}
