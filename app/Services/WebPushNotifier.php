<?php

namespace App\Services;

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
     * @return array{sent: int, failed: int}
     */
    private function deliver(Collection $subscriptions, string $payload): array
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
