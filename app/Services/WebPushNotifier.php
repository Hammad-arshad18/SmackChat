<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Channel;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use App\Support\PushPayloads;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Psr\Http\Client\ClientInterface;
use Throwable;

class WebPushNotifier
{
    private ?ClientInterface $http = null;

    public function useClient(ClientInterface $client): void
    {
        $this->http = $client;
    }

    public function publicKey(): ?string
    {
        return $this->keys()['publicKey'] ?? null;
    }

    public function notify(Channel $channel, User $author, Message $message, string $excerpt): void
    {
        $recipientIds = PushPayloads::channelAudience($channel);

        if ($recipientIds === null || $recipientIds->isEmpty()) {
            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $recipientIds)
            ->where('user_id', '!=', $author->id)
            ->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $payload = json_encode(PushPayloads::message($channel, $author, $message, $excerpt), JSON_UNESCAPED_UNICODE);

        $this->deliver($subscriptions, $payload);
    }

    /**
     * Wake a callee whose tab is closed. This is a notification, not a live
     * WebRTC ring: tapping it opens the conversation and joins the call.
     */
    public function ring(Call $call, User $caller): void
    {
        $subscriptions = $this->callSubscriptions($call, [$caller->id]);

        if ($subscriptions === null) {
            return;
        }

        if ($subscriptions->isEmpty()) {
            // Without this line a callee who never turned alerts on looks
            // exactly like a delivery failure.
            Log::info('Call ring skipped: no callee has push alerts on.', ['call_id' => $call->id]);

            return;
        }

        $ringFor = PushPayloads::ringSeconds();
        $payload = json_encode(PushPayloads::ring($call, $caller), JSON_UNESCAPED_UNICODE);

        // A ring is worthless a minute later. Keep it short-lived and give it a
        // topic so the "call ended" push replaces it if it is still queued.
        $this->deliver($subscriptions, $payload, [
            'TTL' => $ringFor,
            'urgency' => 'high',
            'topic' => 'call-'.$call->id,
        ]);
    }

    /**
     * Tell closed apps the call is over so the ringing notification goes away.
     */
    public function cancelRing(Call $call): void
    {
        // People who were in the call already know it ended. Only the ones
        // who were rung and never picked up still have a ringing notification.
        $joined = PushPayloads::joinedUserIds($call);
        $subscriptions = $this->callSubscriptions($call, $joined);

        if ($subscriptions === null || $subscriptions->isEmpty()) {
            return;
        }

        $answered = count($joined) > 1;
        $payload = json_encode(PushPayloads::callEnded($call, $answered), JSON_UNESCAPED_UNICODE);

        $this->deliver($subscriptions, $payload, [
            'TTL' => 60 * 60,
            'urgency' => 'high',
            'topic' => 'call-'.$call->id,
        ]);
    }

    /**
     * Everyone who could be rung for this call, minus the given people.
     *
     * @param  array<int, int>  $exceptUserIds
     * @return Collection<int, PushSubscription>|null
     */
    private function callSubscriptions(Call $call, array $exceptUserIds): ?Collection
    {
        $call->loadMissing(['channel.workspace']);
        $channel = $call->channel;

        if (! $channel || ! $channel->workspace) {
            return null;
        }

        $recipientIds = PushPayloads::channelAudience($channel);

        return PushSubscription::query()
            ->whereIn('user_id', $recipientIds)
            ->whereNotIn('user_id', $exceptUserIds)
            ->get();
    }

    /**
     * @return array{subscriptions: int, sent: int, failed: int}
     */
    public function ping(User $user): array
    {
        $subscriptions = PushSubscription::query()->where('user_id', $user->id)->get();
        $payload = json_encode([
            'title' => 'SmackChat',
            'body' => 'Alerts are on for this phone.',
            'url' => '/app',
            'tag' => 'smack-test-'.$user->id.'-'.now()->timestamp,
        ], JSON_UNESCAPED_UNICODE);

        return array_merge(
            ['subscriptions' => $subscriptions->count()],
            $this->deliver($subscriptions, $payload),
        );
    }

    /**
     * @param  Collection<int, PushSubscription>  $subscriptions
     * @param  array<string, mixed>  $options  TTL, urgency and topic for this push
     * @return array{sent: int, failed: int}
     */
    private function deliver(Collection $subscriptions, string $payload, array $options = []): array
    {
        $result = ['sent' => 0, 'failed' => 0];

        if ($subscriptions->isEmpty()) {
            return $result;
        }

        $keys = $this->keys();

        if ($keys === null) {
            Log::warning('Push skipped because VAPID keys are missing.');

            return ['sent' => 0, 'failed' => $subscriptions->count()];
        }

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $keys['subject'],
                    'publicKey' => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ],
            ], [], $this->http ?? new Client([
                'timeout' => 12,
                'connect_timeout' => 5,
            ]));
            $webPush->setAutomaticPadding(0);
            $webPush->setDefaultOptions([
                'TTL' => 60 * 60 * 12,
                'urgency' => 'high',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return ['sent' => 0, 'failed' => $subscriptions->count()];
        }

        foreach ($subscriptions as $subscription) {
            try {
                $webPush->queueNotification(
                    Subscription::create([
                        'endpoint' => $subscription->endpoint,
                        'publicKey' => $subscription->public_key,
                        'authToken' => $subscription->auth_token,
                        'contentEncoding' => $subscription->content_encoding ?: 'aes128gcm',
                    ]),
                    $payload,
                    $options,
                );
            } catch (Throwable $exception) {
                $result['failed']++;
                report($exception);
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSuccess()) {
                    $result['sent']++;

                    continue;
                }

                $result['failed']++;
                $status = $report->getResponse()?->getStatusCode();
                Log::warning('Push delivery failed.', [
                    'status' => $status,
                    'reason' => $report->getReason(),
                ]);

                if ($report->isSubscriptionExpired() || in_array($status, [401, 403], true)) {
                    PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (Throwable $exception) {
            report($exception);
            $result['failed'] = max($result['failed'], $subscriptions->count());
        }

        return $result;
    }

    /**
     * @return array{subject: string, publicKey: string, privateKey: string}|null
     */
    private function keys(): ?array
    {
        $subject = (string) config('webpush.subject', 'mailto:notifications@smack.chat');
        $public = config('webpush.public_key');
        $private = config('webpush.private_key');

        if (is_string($public) && $public !== '' && is_string($private) && $private !== '') {
            return [
                'subject' => $subject,
                'publicKey' => $public,
                'privateKey' => $private,
            ];
        }

        $path = storage_path('app/vapid.json');

        try {
            if (! is_file($path)) {
                $directory = dirname($path);
                if (! is_dir($directory)) {
                    File::makeDirectory($directory, 0755, true);
                }

                $handle = fopen($path, 'c+');
                if ($handle === false) {
                    return null;
                }

                try {
                    flock($handle, LOCK_EX);
                    $existing = stream_get_contents($handle);
                    if (! is_string($existing) || trim($existing) === '') {
                        $created = VAPID::createVapidKeys();
                        rewind($handle);
                        ftruncate($handle, 0);
                        fwrite($handle, json_encode([
                            'subject' => $subject,
                            'publicKey' => $created['publicKey'],
                            'privateKey' => $created['privateKey'],
                        ], JSON_THROW_ON_ERROR));
                        fflush($handle);
                    }
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }

            $stored = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($stored) || empty($stored['publicKey']) || empty($stored['privateKey'])) {
            return null;
        }

        return [
            'subject' => $stored['subject'] ?? $subject,
            'publicKey' => $stored['publicKey'],
            'privateKey' => $stored['privateKey'],
        ];
    }
}
