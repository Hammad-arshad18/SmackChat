<?php

namespace App\Services;

use App\Models\Call;
use App\Models\CallParticipant;
use App\Models\CallSignal;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Live audio/video huddles. Media is peer to peer WebRTC; only the SDP/ICE
 * handshake passes through the server. Ably delivers a nudge so members see a
 * ring instantly, and the same signals are also readable over HTTP so calls
 * keep working when Ably is missing or a custom key is not set yet.
 */
class CallService
{
    public function __construct(
        private AblyPublisher $ably,
        private WebPushNotifier $push,
        private NativePushNotifier $nativePush,
    ) {}

    public function limit(Workspace $workspace): int
    {
        return $workspace->plan === 'archive'
            ? max(2, (int) config('smackchat.call_group_limit', 8))
            : 2;
    }

    public function start(Channel $channel, User $user, string $kind): Call
    {
        $created = false;

        $call = DB::transaction(function () use ($channel, $user, $kind, &$created) {
            // Two people pressing "start" at once should share one huddle, not
            // create two. Lock the channel row before looking for a live call.
            Channel::query()->lockForUpdate()->findOrFail($channel->id);
            $existing = $this->liveCall($channel);

            if ($existing) {
                // A call left behind (the other phone was swiped away, or a
                // tab closed) is still "live" until someone prunes it. Joining
                // it would never ring anyone, so only a call that someone else
                // is really in is joined; a leftover is ended and rung afresh.
                $this->pruneStale($existing);
                $existing->refresh();
                $othersSeated = $existing->participants()
                    ->whereNull('left_at')
                    ->where('user_id', '!=', $user->id)
                    ->exists();

                if ($existing->status === Call::LIVE && $othersSeated) {
                    return $this->join($existing, $user);
                }

                $this->end($existing);
            }

            $call = Call::create([
                'workspace_id' => $channel->workspace_id,
                'channel_id' => $channel->id,
                'started_by' => $user->id,
                'kind' => $kind,
                'status' => Call::LIVE,
                'started_at' => now(),
            ]);

            $this->join($call, $user);

            $this->nudge($call, 'call.ring', [
                'kind' => $call->kind,
                'started_by' => $call->started_by,
            ]);

            $created = true;

            return $call->fresh();
        });

        if ($created) {
            // A closed tab cannot ring, but a push notification can bring the
            // callee back into the app. Never let that delay the call itself.
            $push = $this->push;
            $nativePush = $this->nativePush;
            defer(function () use ($push, $nativePush, $call, $user): void {
                try {
                    $push->ring($call, $user);
                } catch (Throwable $exception) {
                    report($exception);
                }

                try {
                    $nativePush->ring($call, $user);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
        }

        return $call;
    }

    public function join(Call $call, User $user): Call
    {
        return DB::transaction(function () use ($call, $user) {
            $locked = Call::query()->lockForUpdate()->findOrFail($call->id);

            abort_if($locked->status === Call::ENDED, 410, 'That huddle has ended.');

            $this->pruneStale($locked);

            abort_if($locked->status === Call::ENDED, 410, 'That huddle has ended.');

            $seated = $locked->participants()
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->exists();

            if (! $seated && $locked->participants()->whereNull('left_at')->count() >= $this->limit($locked->workspace)) {
                abort(409, $locked->workspace->plan === 'archive'
                    ? 'This huddle is full.'
                    : 'The free plan allows one-to-one huddles. The forever archive plan adds group huddles.');
            }

            // A join is a new browser session. Offers, answers and candidates
            // left over from an earlier tab belong to a peer connection that
            // no longer exists; replaying them breaks the new handshake.
            $locked->signals()
                ->where(fn ($query) => $query->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id))
                ->delete();

            $joinedAt = now();

            CallParticipant::updateOrCreate(
                ['call_id' => $locked->id, 'user_id' => $user->id],
                ['joined_at' => $joinedAt, 'left_at' => null, 'last_seen_at' => $joinedAt],
            );

            $locked->forceFill(['status' => Call::LIVE, 'ended_at' => null])->save();

            // Always nudge, even for a rejoin: the others must drop their old
            // connection to this person and build a fresh one.
            $this->nudge($locked, 'call.joined', [
                'user_id' => $user->id,
                'session' => $joinedAt->toIso8601String(),
                'rejoin' => $seated,
            ]);

            return $locked->fresh();
        });
    }

    public function leave(Call $call, User $user): void
    {
        DB::transaction(function () use ($call, $user) {
            // Two people hanging up at the same moment must not each see the
            // other as still seated and leave a call nobody is in.
            $locked = Call::query()->lockForUpdate()->findOrFail($call->id);

            if ($locked->status === Call::ENDED) {
                return;
            }

            $locked->participants()
                ->where('user_id', $user->id)
                ->whereNull('left_at')
                ->update(['left_at' => now()]);

            // Their handshake belongs to a connection that is closed now.
            $locked->signals()
                ->where(fn ($query) => $query->where('to_user_id', $user->id)->orWhere('from_user_id', $user->id))
                ->delete();

            if ($this->isOver($locked)) {
                $this->end($locked, 'call.ended');
            } else {
                $this->nudge($locked, 'call.left', ['user_id' => $user->id]);
            }
        });
    }

    /**
     * A call needs two people. It is over when nobody is seated, or when one
     * person is left after someone else had joined: hanging up ends the call
     * for the other side too, instead of leaving them in an empty room.
     */
    private function isOver(Call $call): bool
    {
        $seated = $call->participants()->whereNull('left_at')->count();

        if ($seated === 0) {
            // A call that was just created has no participant row yet; the
            // starter is being seated right now.
            return $call->participants()->exists();
        }

        return $seated === 1 && $call->participants()->count() > 1;
    }

    public function end(Call $call, string $event = 'call.ended'): void
    {
        if ($call->status === Call::ENDED) {
            return;
        }

        $call->forceFill(['status' => Call::ENDED, 'ended_at' => now()])->save();
        $call->signals()->delete();

        $this->nudge($call, $event);

        // Take the ring off phones whose app is closed.
        $push = $this->push;
        $nativePush = $this->nativePush;
        defer(function () use ($push, $nativePush, $call): void {
            try {
                $push->cancelRing($call);
            } catch (Throwable $exception) {
                report($exception);
            }

            try {
                $nativePush->cancelRing($call);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /**
     * The callee said no from the ring screen or the notification. A one-to-one
     * call ends; in a channel huddle only the caller is told.
     */
    public function decline(Call $call, User $user): void
    {
        if ($call->status === Call::ENDED || $this->isSeated($call, $user)) {
            return;
        }

        $call->loadMissing('channel');

        $this->nudge($call, 'call.declined', ['user_id' => $user->id]);

        if ($call->channel?->type === Channel::DM) {
            $this->end($call, 'call.ended');
        }
    }

    /**
     * End calls nobody answered and calls whose people all went away. Runs
     * from the scheduler so a caller who closed the tab does not leave phones
     * ringing for a call that no one can join.
     */
    public function sweep(): int
    {
        $ended = 0;

        Call::query()
            ->where('status', Call::LIVE)
            ->orderBy('id')
            ->each(function (Call $call) use (&$ended) {
                $this->pruneStale($call);

                if ($call->status === Call::ENDED) {
                    $ended++;
                }
            });

        return $ended;
    }

    public function signal(Call $call, User $user, int $to, string $type, array $payload): void
    {
        abort_unless($this->isSeated($call, $user), 403, 'Join the huddle before sending signals.');
        abort_unless($this->isSeated($call, $to), 422, 'That person is no longer in the huddle.');

        // TrimStrings removes the final CRLF from request strings. SDP needs it.
        if ($type !== 'candidate' && isset($payload['sdp']) && is_string($payload['sdp'])) {
            $payload['sdp'] = str_ends_with($payload['sdp'], "\r\n")
                ? $payload['sdp']
                : $payload['sdp']."\r\n";
        }

        CallSignal::create([
            'call_id' => $call->id,
            'from_user_id' => $user->id,
            'to_user_id' => $to,
            'type' => $type,
            'payload' => $payload,
        ]);

        // SDP is only useful while a call is being set up; keep the table small.
        $call->signals()->where('created_at', '<', now()->subMinutes(10))->delete();

        $this->nudge($call, 'call.signal', ['to' => $to, 'from' => $user->id]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function signals(Call $call, User $user, int $after): array
    {
        abort_unless($this->isSeated($call, $user), 403, 'Join the huddle before reading signals.');

        $this->touch($call, $user);

        return $call->signals()
            ->where('to_user_id', $user->id)
            ->where('id', '>', $after)
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->map(fn (CallSignal $signal) => [
                'id' => $signal->id,
                'from' => $signal->from_user_id,
                'type' => $signal->type,
                'payload' => $signal->payload,
            ])
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function active(Workspace $workspace, User $user): array
    {
        $channelIds = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($user) {
                $query->where('type', Channel::PUBLIC)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->pluck('id');

        return Call::query()
            ->with(['participants.user:id,name,avatar_color,avatar_path,updated_at', 'channel:id,slug,name,type'])
            ->whereIn('channel_id', $channelIds)
            ->where('status', Call::LIVE)
            ->where('started_at', '>', now()->subHours(6))
            ->orderBy('id')
            ->get()
            ->map(fn (Call $call) => $this->present($call, $user))
            ->values()
            ->all();
    }

    public function present(Call $call, User $viewer): array
    {
        $this->pruneStale($call);
        $call->unsetRelation('participants');
        $call->loadMissing([
            'workspace',
            'participants.user:id,name,avatar_color,avatar_path,updated_at',
            'channel:id,slug,name,type',
        ]);

        $participants = $call->participants
            ->whereNull('left_at')
            ->map(fn (CallParticipant $participant) => [
                'user_id' => $participant->user_id,
                'name' => $participant->user?->name ?? 'Someone',
                'avatar_color' => $participant->user?->avatar_color,
                'avatar_url' => $participant->user?->avatarUrl(),
                'joined_at' => optional($participant->joined_at)?->toIso8601String(),
            ])
            ->values();

        $channel = $call->channel;
        $label = $channel?->name;

        if ($channel?->type === Channel::DM) {
            $peer = $channel->members()
                ->where('users.id', '!=', $viewer->id)
                ->value('users.name');

            $label = $peer ?: $label;
        }

        return [
            'id' => $call->id,
            'workspace_id' => $call->workspace_id,
            'channel_id' => $call->channel_id,
            'channel_slug' => $channel?->slug,
            'channel_label' => $label,
            'channel_type' => $channel?->type,
            'kind' => $call->kind,
            'status' => $call->status,
            'started_by' => $call->started_by,
            'started_at' => optional($call->started_at)?->toIso8601String(),
            'max_participants' => $this->limit($call->workspace),
            'group_enabled' => $call->workspace->plan === 'archive',
            'joined' => $call->participants->contains(fn (CallParticipant $participant) => $participant->user_id === $viewer->id && $participant->left_at === null),
            // True once the viewer has been in this call and hung up. The
            // client must not ring them for it again.
            'was_in' => $call->participants->contains(fn (CallParticipant $participant) => $participant->user_id === $viewer->id && $participant->left_at !== null),
            'participants' => $participants,
        ];
    }

    public function liveCall(Channel $channel): ?Call
    {
        return Call::query()
            ->where('channel_id', $channel->id)
            ->where('status', Call::LIVE)
            ->where('started_at', '>', now()->subHours(6))
            ->orderByDesc('id')
            ->first();
    }

    public function touch(Call $call, User $user): void
    {
        $call->participants()
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->update(['last_seen_at' => now()]);
    }

    public function isSeated(Call $call, User|int $user): bool
    {
        $id = $user instanceof User ? $user->id : $user;

        return $call->participants()
            ->where('user_id', $id)
            ->whereNull('left_at')
            ->exists();
    }

    /**
     * Drop people whose tab went away without saying goodbye, so a slot frees
     * up and the participant list stays honest. A call that rang out with no
     * one answering ends here too.
     */
    public function pruneStale(Call $call): void
    {
        $cutoff = now()->subSeconds(max(30, (int) config('smackchat.call_stale_seconds', 120)));

        $call->participants()
            ->whereNull('left_at')
            ->where('last_seen_at', '<', $cutoff)
            ->update(['left_at' => now()]);

        if ($call->status !== Call::LIVE) {
            return;
        }

        if ($this->isOver($call)) {
            $this->end($call);

            return;
        }

        $seated = $call->participants()->whereNull('left_at')->count();
        $ringFor = max(30, (int) config('smackchat.call_ring_seconds', 75));
        $unanswered = $seated === 1
            && $call->participants()->count() === 1
            && $call->started_at !== null
            && $call->started_at->lt(now()->subSeconds($ringFor));

        if ($unanswered) {
            $this->end($call);
        }
    }

    private function nudge(Call $call, string $event, array $extra = []): void
    {
        $this->ably->publishCall(
            $call->workspace_id,
            $call->channel_id,
            array_merge([
                'event' => $event,
                'call_id' => $call->id,
                'channel_id' => $call->channel_id,
            ], $extra),
        );
    }
}
