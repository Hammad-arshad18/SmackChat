<?php

namespace App\Services;

use App\Models\Call;
use App\Models\Channel;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use GuzzleHttp\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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
        $recipientIds = $channel->isOpen()
            ? DB::table('workspace_members')->where('workspace_id', $channel->workspace_id)->pluck('user_id')
            : $channel->members()->pluck('users.id');

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

        $channel->loadMissing('workspace');
        $parentId = $message->parent_id;
        $label = $channel->type === Channel::DM ? 'Direct message' : $channel->name;
        $title = $parentId ? 'Thread in '.$label : $label;
        $body = trim($author->name.': '.($excerpt !== '' ? $excerpt : 'New message'));
        $url = '/w/'.$channel->workspace->slug.'/c/'.$channel->slug;
        $url .= $parentId
            ? '?m='.$parentId.'&reply='.$message->id
            : '?m='.$message->id;

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'smack-m-'.$message->id,
            'channel_id' => $channel->id,
            'parent_id' => $parentId,
        ], JSON_UNESCAPED_UNICODE);

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

        $channel = $call->channel;
        $isDm = $channel->type === Channel::DM;
        $title = $isDm
            ? ($call->kind === Call::VIDEO ? 'Incoming video call' : 'Incoming call')
            : 'Incoming huddle';
        $body = $isDm
            ? $caller->name.' is calling'
            : $caller->name.' started a huddle in #'.$channel->name;
        $startedAt = $call->started_at ?? now();
        $ringFor = max(30, (int) config('smackchat.call_ring_seconds', 75));

        $payload = json_encode([
            'type' => 'call',
            'title' => $title,
            'body' => $body,
            'url' => '/w/'.$channel->workspace->slug.'/c/'.$channel->slug.'?call='.$call->id,
            'tag' => 'smack-call-'.$call->id,
            'call_id' => $call->id,
            'workspace' => $channel->workspace->slug,
            'kind' => $call->kind,
            'started_at' => $startedAt->getTimestampMs(),
            'expires_at' => $startedAt->copy()->addSeconds($ringFor)->getTimestampMs(),
        ], JSON_UNESCAPED_UNICODE);

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
        $joined = $call->participants()->pluck('user_id')->push($call->started_by)->all();
        $subscriptions = $this->callSubscriptions($call, $joined);

        if ($subscriptions === null || $subscriptions->isEmpty()) {
            return;
        }

        $answered = count(array_unique($joined)) > 1;
        $caller = $call->starter?->name ?? 'Someone';

        $payload = json_encode([
            'type' => 'call-ended',
            'title' => $answered ? 'Call ended' : 'Missed call',
            'body' => $answered ? 'The call has ended.' : 'Missed call from '.$caller,
            'url' => '/w/'.$call->channel->workspace->slug.'/c/'.$call->channel->slug,
            'tag' => 'smack-call-'.$call->id,
            'call_id' => $call->id,
            'answered' => $answered,
        ], JSON_UNESCAPED_UNICODE);

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

        $recipientIds = $channel->isOpen()
            ? DB::table('workspace_members')->where('workspace_id', $channel->workspace_id)->pluck('user_id')
            : $channel->members()->pluck('users.id');

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
