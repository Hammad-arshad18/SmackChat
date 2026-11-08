<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Message;
use App\Models\PushSubscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\VAPID;
use Minishlink\WebPush\WebPush;
use Throwable;

class WebPushNotifier
{
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

        $keys = $this->keys();

        if ($keys === null) {
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
            'tag' => 'smack-'.$channel->id.($parentId ? '-thread-'.$parentId : ''),
            'channel_id' => $channel->id,
            'parent_id' => $parentId,
        ], JSON_UNESCAPED_UNICODE);

        try {
            $webPush = new WebPush([
                'VAPID' => [
                    'subject' => $keys['subject'],
                    'publicKey' => $keys['publicKey'],
                    'privateKey' => $keys['privateKey'],
                ],
            ]);
            $webPush->setDefaultOptions([
                'TTL' => 60 * 60 * 12,
                'urgency' => 'high',
            ]);
        } catch (Throwable $exception) {
            report($exception);

            return;
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
                report($exception);
            }
        }

        try {
            foreach ($webPush->flush() as $report) {
                if ($report->isSubscriptionExpired()) {
                    PushSubscription::query()->where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
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
