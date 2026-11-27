<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Channel;
use App\Models\DevicePushToken;
use App\Models\Message;
use App\Models\User;
use App\Support\PushPayloads;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Push for the native app, beside `WebPushNotifier`, with the same payloads
 * (`PushPayloads`).
 *
 * - Messages go through the Expo Push API to `expo` tokens (iOS) and as FCM
 *   data messages to `fcm` tokens (Android), which need no Expo project.
 * - Rings go to iOS as an APNs VoIP push (PushKit → CallKit) and to Android
 *   as a high-priority FCM data message (full-screen call notification), so
 *   the phone rings while the app is closed. Someone with only an Expo token
 *   gets an ordinary Expo notification instead.
 *
 * Every provider is optional. Without its keys that kind of push is skipped
 * quietly, the way a missing Ably key means polling.
 */
class NativePushNotifier
{
    public const EXPO_URL = 'https://exp.host/--/api/v2/push/send';

    private const FCM_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Android notification channels and the call category the app creates. */
    public const CHANNEL_MESSAGES = 'messages';

    public const CHANNEL_CALLS = 'calls';

    public const CATEGORY_CALL = 'incoming-call';

    private ?ClientInterface $http = null;

    public function useClient(ClientInterface $client): void
    {
        $this->http = $client;
    }

    public function notify(Channel $channel, User $author, Message $message, string $excerpt): void
    {
        $tokens = DevicePushToken::query()
            ->whereIn('kind', [DevicePushToken::EXPO, DevicePushToken::FCM])
            ->whereIn('user_id', PushPayloads::channelAudience($channel))
            ->where('user_id', '!=', $author->id)
            ->get();

        if ($tokens->isEmpty()) {
            return;
        }

        $payload = PushPayloads::message($channel, $author, $message, $excerpt);

        $this->sendExpo($tokens->where('kind', DevicePushToken::EXPO), $payload, [
            'sound' => 'default',
            'priority' => 'high',
            'channelId' => self::CHANNEL_MESSAGES,
        ]);

        // Android phones take FCM straight from Firebase, so they need no
        // Expo project: the app's notification module draws it natively.
        $this->sendFcm($tokens->where('kind', DevicePushToken::FCM), $payload, null, null, [
            'channelId' => self::CHANNEL_MESSAGES,
        ]);
    }

    /**
     * Ring the callees' phones. Caller excluded.
     */
    public function ring(Call $call, User $caller): void
    {
        $tokens = $this->callTokens($call, [$caller->id]);

        if ($tokens === null || $tokens->isEmpty()) {
            return;
        }

        $payload = PushPayloads::ring($call, $caller);
        $expiresAt = intdiv((int) $payload['expires_at'], 1000);
        $ringFor = PushPayloads::ringSeconds();
        $ringable = $this->ringableUserIds($tokens);

        $this->sendVoip($tokens->where('kind', DevicePushToken::VOIP), $payload, $expiresAt, 'call-'.$call->id);
        $this->sendFcm($tokens->where('kind', DevicePushToken::FCM), $payload, $ringFor, 'call-'.$call->id, [
            'channelId' => self::CHANNEL_CALLS,
            'categoryId' => self::CATEGORY_CALL,
        ]);

        // No PushKit/FCM token on file (older build, or permission refused):
        // fall back to a plain notification so the call is not silent.
        $this->sendExpo(
            $tokens->where('kind', DevicePushToken::EXPO)->whereNotIn('user_id', $ringable),
            $payload,
            [
                'sound' => 'default',
                'priority' => 'high',
                'ttl' => $ringFor,
                'channelId' => self::CHANNEL_CALLS,
                'categoryId' => self::CATEGORY_CALL,
            ],
        );
    }

    /**
     * Stop the ringing on phones of people who never picked up.
     */
    public function cancelRing(Call $call): void
    {
        $joined = PushPayloads::joinedUserIds($call);
        $tokens = $this->callTokens($call, $joined);

        if ($tokens === null || $tokens->isEmpty()) {
            return;
        }

        $answered = count($joined) > 1;
        $payload = PushPayloads::callEnded($call, $answered);
        $ringable = $this->ringableUserIds($tokens);

        // A VoIP push must be reported to CallKit, so the app ends the call
        // it is showing (or reports and ends one at once if it has none).
        $this->sendVoip($tokens->where('kind', DevicePushToken::VOIP), $payload, now()->addMinute()->getTimestamp(), 'call-'.$call->id);
        // Android: the same `tag` replaces the ring in the tray. Unanswered,
        // it becomes "Missed call"; answered elsewhere, it is a silent data
        // message and the app takes the ring away.
        $this->sendFcm($tokens->where('kind', DevicePushToken::FCM), $payload, 60, 'call-'.$call->id, [
            'channelId' => self::CHANNEL_MESSAGES,
            'silent' => $answered,
        ]);

        // A visible note: "Missed call" for anyone not answered, and the
        // fallback ring's replacement for people who got the Expo ring.
        // Phones on FCM already got theirs above.
        $fcmUsers = $tokens->where('kind', DevicePushToken::FCM)->pluck('user_id')->unique()->all();
        $expo = $tokens->where('kind', DevicePushToken::EXPO)
            ->whereNotIn('user_id', $answered ? $ringable : $fcmUsers);

        $this->sendExpo($expo, $payload, ['sound' => 'default', 'priority' => 'high', 'channelId' => self::CHANNEL_MESSAGES]);
    }

    /**
     * @param  array<int, int>  $exceptUserIds
     * @return Collection<int, DevicePushToken>|null
     */
    private function callTokens(Call $call, array $exceptUserIds): ?Collection
    {
        $call->loadMissing(['channel.workspace']);
        $channel = $call->channel;

        if (! $channel || ! $channel->workspace) {
            return null;
        }

        return DevicePushToken::query()
            ->whereIn('user_id', PushPayloads::channelAudience($channel))
            ->whereNotIn('user_id', $exceptUserIds)
            ->get();
    }

    /**
     * People who have a phone that can ring natively.
     *
     * @param  Collection<int, DevicePushToken>  $tokens
     * @return array<int, int>
     */
    private function ringableUserIds(Collection $tokens): array
    {
        return $tokens
            ->whereIn('kind', [DevicePushToken::VOIP, DevicePushToken::FCM])
            ->pluck('user_id')
            ->unique()
            ->values()
            ->all();
    }

    // ---------------------------------------------------------------- Expo

    /**
     * @param  Collection<int, DevicePushToken>  $tokens
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $options
     */
    private function sendExpo(Collection $tokens, array $payload, array $options): void
    {
        if ($tokens->isEmpty()) {
            return;
        }

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
        $accessToken = config('services.expo.access_token');

        if (is_string($accessToken) && $accessToken !== '') {
            $headers['Authorization'] = 'Bearer '.$accessToken;
        }

        // Expo takes up to 100 messages per request and answers with one
        // ticket per message, in order.
        foreach ($tokens->values()->chunk(100) as $chunk) {
            $chunk = $chunk->values();
            $messages = $chunk->map(fn (DevicePushToken $token) => array_merge([
                'to' => $token->token,
                'title' => $payload['title'],
                'body' => $payload['body'],
                'data' => $payload,
            ], $options))->all();

            $response = $this->send(new Request('POST', self::EXPO_URL, $headers, json_encode($messages, JSON_UNESCAPED_UNICODE)));

            if (! $response) {
                continue;
            }

            if ($response->getStatusCode() >= 300) {
                Log::warning('Expo push failed.', ['status' => $response->getStatusCode()]);

                continue;
            }

            $tickets = json_decode((string) $response->getBody(), true)['data'] ?? [];

            foreach ($chunk as $index => $token) {
                $ticket = $tickets[$index] ?? null;

                if (! is_array($ticket) || ($ticket['status'] ?? null) !== 'error') {
                    continue;
                }

                $error = $ticket['details']['error'] ?? null;
                Log::warning('Expo push rejected.', ['error' => $error]);

                if ($error === 'DeviceNotRegistered') {
                    $token->delete();
                }
            }
        }
    }

    // ---------------------------------------------------------------- APNs

    /**
     * @param  Collection<int, DevicePushToken>  $tokens
     * @param  array<string, mixed>  $payload
     */
    private function sendVoip(Collection $tokens, array $payload, int $expiresAt, string $collapseId): void
    {
        if ($tokens->isEmpty()) {
            return;
        }

        $jwt = $this->apnsJwt();

        if ($jwt === null) {
            Log::debug('VoIP push skipped: APNs is not configured.');

            return;
        }

        $host = config('services.apns.production')
            ? 'https://api.push.apple.com'
            : 'https://api.sandbox.push.apple.com';
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

        foreach ($tokens as $token) {
            $request = new Request('POST', $host.'/3/device/'.$token->token, [
                'authorization' => 'bearer '.$jwt,
                'apns-topic' => config('services.apns.bundle_id').'.voip',
                'apns-push-type' => 'voip',
                'apns-priority' => '10',
                'apns-expiration' => (string) $expiresAt,
                'apns-collapse-id' => $collapseId,
                'content-type' => 'application/json',
            ], $body, '2.0');

            $response = $this->send($request);

            if (! $response || $response->getStatusCode() === 200) {
                continue;
            }

            $status = $response->getStatusCode();
            $reason = json_decode((string) $response->getBody(), true)['reason'] ?? null;
            Log::warning('APNs push rejected.', ['status' => $status, 'reason' => $reason]);

            if ($status === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered'], true)) {
                $token->delete();
            }

            if (in_array($reason, ['ExpiredProviderToken', 'InvalidProviderToken'], true)) {
                Cache::forget($this->apnsCacheKey());
            }
        }
    }

    /**
     * The provider token APNs wants: an ES256 JWT signed with the .p8 key.
     * Apple refuses a new one more often than every 20 minutes, so it is
     * kept for 50 (it is valid for 60).
     */
    private function apnsJwt(): ?string
    {
        $keyId = config('services.apns.key_id');
        $teamId = config('services.apns.team_id');
        $path = config('services.apns.key_path');
        $bundle = config('services.apns.bundle_id');

        if (! $keyId || ! $teamId || ! $bundle || ! is_string($path) || ! is_readable($path)) {
            return null;
        }

        return Cache::remember($this->apnsCacheKey(), 50 * 60, function () use ($keyId, $teamId, $path) {
            $key = openssl_pkey_get_private((string) file_get_contents($path));

            if ($key === false) {
                Log::warning('APNs key could not be read.');

                return null;
            }

            $input = $this->base64Url(json_encode(['alg' => 'ES256', 'kid' => $keyId]))
                .'.'.$this->base64Url(json_encode(['iss' => $teamId, 'iat' => time()]));

            if (! openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
                return null;
            }

            return $input.'.'.$this->base64Url(self::derToJose($der, 32));
        });
    }

    private function apnsCacheKey(): string
    {
        return 'native-push:apns-jwt:'.config('services.apns.key_id');
    }

    /**
     * openssl signs ECDSA as DER; a JWT wants r || s, each padded to the
     * curve size.
     */
    public static function derToJose(string $der, int $partLength): string
    {
        $offset = 2;

        if ((ord($der[1]) & 0x80) !== 0) {
            $offset += ord($der[1]) & 0x7F;
        }

        $parts = [];

        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            $value = substr($der, $offset + 2, $length);
            $offset += 2 + $length;
            $value = ltrim($value, "\x00");
            $parts[] = str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
        }

        return $parts[0].$parts[1];
    }

    // ----------------------------------------------------------------- FCM

    /**
     * Data only, no `notification` block, in the shape expo-notifications
     * reads on Android: `title` and `message` are drawn natively (even with
     * the app closed) on `channelId`, `tag` replaces an earlier notice with
     * the same tag, and `body` is the JSON the app sees as the notification's
     * data. The flat fields stay for the app's background task.
     *
     * @param  Collection<int, DevicePushToken>  $tokens
     * @param  array<string, mixed>  $payload
     * @param  array{channelId?: string, categoryId?: string, silent?: bool}  $display
     */
    private function sendFcm(Collection $tokens, array $payload, ?int $ttl, ?string $collapseKey, array $display = []): void
    {
        if ($tokens->isEmpty()) {
            return;
        }

        $accessToken = $this->fcmAccessToken();

        if ($accessToken === null) {
            Log::debug('FCM push skipped: FCM is not configured.');

            return;
        }

        $url = 'https://fcm.googleapis.com/v1/projects/'.config('services.fcm.project_id').'/messages:send';
        // FCM data values must all be strings.
        $data = array_map(fn ($value) => match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => '',
            default => (string) $value,
        }, $payload);

        $silent = (bool) ($display['silent'] ?? false);
        $data = array_merge($data, array_filter([
            'title' => $silent ? '' : (string) ($payload['title'] ?? ''),
            'message' => $silent ? '' : (string) ($payload['body'] ?? ''),
            'body' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'channelId' => $display['channelId'] ?? null,
            'categoryId' => $display['categoryId'] ?? null,
        ], fn ($value) => $value !== null));
        $android = array_filter([
            'priority' => 'HIGH',
            'ttl' => $ttl !== null ? $ttl.'s' : null,
            'collapse_key' => $collapseKey,
        ], fn ($value) => $value !== null);

        foreach ($tokens as $token) {
            $body = json_encode(['message' => [
                'token' => $token->token,
                'data' => $data,
                'android' => $android,
            ]], JSON_UNESCAPED_UNICODE);

            $response = $this->send(new Request('POST', $url, [
                'Authorization' => 'Bearer '.$accessToken,
                'Content-Type' => 'application/json',
            ], $body));

            if (! $response || $response->getStatusCode() === 200) {
                continue;
            }

            $status = $response->getStatusCode();
            $error = json_decode((string) $response->getBody(), true)['error'] ?? [];
            $codes = collect($error['details'] ?? [])->pluck('errorCode')->filter()->all();
            Log::warning('FCM push rejected.', ['status' => $status, 'codes' => $codes]);

            $badToken = in_array('UNREGISTERED', $codes, true)
                || $status === 404
                || ($status === 400 && str_contains(strtolower((string) ($error['message'] ?? '')), 'registration token'));

            if ($badToken) {
                $token->delete();
            }

            if ($status === 401) {
                Cache::forget($this->fcmCacheKey());
            }
        }
    }

    /**
     * An OAuth access token for FCM, from the service account JSON.
     */
    private function fcmAccessToken(): ?string
    {
        $projectId = config('services.fcm.project_id');
        $path = config('services.fcm.credentials_path');

        if (! $projectId || ! is_string($path) || ! is_readable($path)) {
            return null;
        }

        $cached = Cache::get($this->fcmCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $credentials = json_decode((string) file_get_contents($path), true);

        if (! is_array($credentials) || empty($credentials['client_email']) || empty($credentials['private_key'])) {
            Log::warning('FCM credentials file is not a service account key.');

            return null;
        }

        $tokenUri = $credentials['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $now = time();
        $input = $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
            .'.'.$this->base64Url(json_encode([
                'iss' => $credentials['client_email'],
                'scope' => self::FCM_SCOPE,
                'aud' => $tokenUri,
                'iat' => $now,
                'exp' => $now + 3600,
            ]));
        $key = openssl_pkey_get_private($credentials['private_key']);

        if ($key === false || ! openssl_sign($input, $signature, $key, OPENSSL_ALGO_SHA256)) {
            Log::warning('FCM service account key could not sign.');

            return null;
        }

        $response = $this->send(new Request('POST', $tokenUri, [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $input.'.'.$this->base64Url($signature),
        ])));

        if (! $response || $response->getStatusCode() !== 200) {
            Log::warning('FCM access token request failed.', ['status' => $response?->getStatusCode()]);

            return null;
        }

        $body = json_decode((string) $response->getBody(), true);
        $accessToken = $body['access_token'] ?? null;

        if (! is_string($accessToken) || $accessToken === '') {
            return null;
        }

        Cache::put($this->fcmCacheKey(), $accessToken, max(60, (int) ($body['expires_in'] ?? 3600) - 300));

        return $accessToken;
    }

    private function fcmCacheKey(): string
    {
        return 'native-push:fcm-token:'.config('services.fcm.project_id');
    }

    // ---------------------------------------------------------------- HTTP

    private function send(Request $request): ?ResponseInterface
    {
        try {
            return $this->client()->sendRequest($request);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }
    }

    private function client(): ClientInterface
    {
        return $this->http ??= new Client([
            'timeout' => 10,
            'connect_timeout' => 5,
        ]);
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
