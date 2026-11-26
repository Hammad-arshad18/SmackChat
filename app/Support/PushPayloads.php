<?php

namespace App\Support;

use App\Models\Call;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What a push says, shared by Web Push and the native app so the two never
 * drift. None of it may carry user-written text: an end-to-end message is
 * only ever "New message".
 */
class PushPayloads
{
    /**
     * @return array<string, mixed>
     */
    public static function message(Channel $channel, User $author, Message $message, string $excerpt): array
    {
        $channel->loadMissing('workspace');
        $parentId = $message->parent_id;
        $label = $channel->type === Channel::DM ? 'Direct message' : $channel->name;
        $title = $parentId ? 'Thread in '.$label : $label;
        // Only a server-written post (welcome, attendance checkout) has text
        // the server may repeat. A user's post is sealed end to end.
        $excerpt = $message->cipher === 'server' ? trim($excerpt) : '';
        $body = trim($author->name.': '.($excerpt !== '' ? $excerpt : 'New message'));
        $url = '/w/'.$channel->workspace->slug.'/c/'.$channel->slug;
        $url .= $parentId
            ? '?m='.OpaqueId::encode($parentId).'&reply='.OpaqueId::encode($message->id)
            : '?m='.OpaqueId::encode($message->id);

        return [
            'type' => 'message',
            'title' => $title,
            'body' => $body,
            'url' => $url,
            'tag' => 'smack-m-'.$message->id,
            'workspace' => $channel->workspace->slug,
            'channel' => $channel->slug,
            'channel_id' => $channel->id,
            'parent_id' => $parentId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function ring(Call $call, User $caller): array
    {
        $call->loadMissing(['channel.workspace']);
        $channel = $call->channel;
        $isDm = $channel->type === Channel::DM;
        $startedAt = $call->started_at ?? now();
        $ringFor = self::ringSeconds();

        return [
            'type' => 'call',
            // The caller is the headline, the way a phone shows a call.
            'title' => $caller->name,
            'body' => $isDm
                ? ($call->kind === Call::VIDEO ? 'Incoming video call' : 'Incoming voice call')
                : 'Huddle in #'.$channel->name,
            'url' => '/w/'.$channel->workspace->slug.'/c/'.$channel->slug.'?call='.$call->id,
            'tag' => 'smack-call-'.$call->id,
            'call_id' => $call->id,
            'workspace' => $channel->workspace->slug,
            'channel' => $channel->slug,
            'caller_id' => $caller->id,
            'caller_name' => $caller->name,
            'is_dm' => $isDm,
            'kind' => $call->kind,
            'started_at' => $startedAt->getTimestampMs(),
            'expires_at' => $startedAt->copy()->addSeconds($ringFor)->getTimestampMs(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function callEnded(Call $call, bool $answered): array
    {
        $call->loadMissing(['channel.workspace', 'starter']);
        $caller = $call->starter?->name ?? 'Someone';

        return [
            'type' => 'call-ended',
            'title' => $answered ? 'Call ended' : 'Missed call',
            'body' => $answered ? 'The call has ended.' : 'Missed call from '.$caller,
            'url' => '/w/'.$call->channel->workspace->slug.'/c/'.$call->channel->slug,
            'tag' => 'smack-call-'.$call->id,
            'call_id' => $call->id,
            'workspace' => $call->channel->workspace->slug,
            'answered' => $answered,
        ];
    }

    public static function ringSeconds(): int
    {
        return max(30, (int) config('smackchat.call_ring_seconds', 75));
    }

    /**
     * Everyone who reads the channel.
     *
     * @return Collection<int, int>
     */
    public static function channelAudience(Channel $channel): Collection
    {
        return $channel->isOpen()
            ? DB::table('workspace_members')->where('workspace_id', $channel->workspace_id)->pluck('user_id')
            : $channel->members()->pluck('users.id');
    }

    /**
     * The caller plus everyone who took a seat. They know the call ended.
     *
     * @return array<int, int>
     */
    public static function joinedUserIds(Call $call): array
    {
        return array_values(array_unique(
            $call->participants()->pluck('user_id')->push($call->started_by)->map(fn ($id) => (int) $id)->all()
        ));
    }
}
