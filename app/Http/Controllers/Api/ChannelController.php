<?php

namespace App\Http\Controllers\Api;

use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Services\ChannelAccess;
use App\Services\MessageCipher;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class ChannelController extends ApiController
{
    public function index(Request $request, MessageCipher $cipher)
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $channels = Channel::query()
            ->with('members:id,name,avatar_color')
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($user) {
                $query->where('type', Channel::PUBLIC)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->orderBy('name')
            ->get();

        $ids = $channels->pluck('id');
        $latestIds = Message::query()
            ->retained($workspace)
            ->whereIn('channel_id', $ids)
            ->whereNull('deleted_at')
            ->selectRaw('max(id) as id')
            ->groupBy('channel_id')
            ->pluck('id');

        $latest = Message::query()->with('user')->whereIn('id', $latestIds)->get()->keyBy('channel_id');
        $unreadCounts = Message::query()
            ->retained($workspace)
            ->whereIn('messages.channel_id', $ids)
            ->whereNull('messages.deleted_at')
            ->where('messages.user_id', '!=', $user->id)
            ->leftJoin('channel_user as reads', function ($join) use ($user) {
                $join->on('reads.channel_id', '=', 'messages.channel_id')
                    ->where('reads.user_id', '=', $user->id);
            })
            ->where(function ($query) {
                $query->whereNull('reads.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'reads.last_read_at');
            })
            ->groupBy('messages.channel_id')
            ->select('messages.channel_id')
            ->selectRaw('count(*) as unread_count')
            ->get()
            ->mapWithKeys(fn ($row) => [(int) $row->channel_id => (int) $row->unread_count]);

        $payload = $channels->map(function (Channel $channel) use ($user, $latest, $unreadCounts, $cipher) {
            $message = $latest->get($channel->id);
            $excerpt = null;

            $latestSealed = null;

            if ($message && $message->cipher === 'e2e') {
                $latestSealed = $message->ciphertext;
            } elseif ($message) {
                $document = $cipher->decrypt($message->ciphertext);
                $excerpt = $document ? mb_strimwidth(trim(preg_replace('/\s+/', ' ', RichText::plain($document)) ?? ''), 0, 80, '…') : null;
            }

            $others = $channel->members->where('id', '!=', $user->id)->values();
            $label = $channel->name;

            if ($channel->type === Channel::DM) {
                $label = $others->first()->name ?? 'Direct message';
            }

            return [
                'id' => $channel->id,
                'name' => $channel->name,
                'slug' => $channel->slug,
                'type' => $channel->type,
                'label' => $label,
                'topic' => $channel->topic,
                'description' => $channel->description,
                'excerpt' => $excerpt,
                'latest_sealed' => $latestSealed,
                'unread' => $unreadCounts->get($channel->id, 0),
                'last_at' => $message?->created_at?->toIso8601String(),
                'people' => $others->map(fn (User $person) => [
                    'id' => $person->id,
                    'name' => $person->name,
                    'avatar_color' => $person->avatar_color,
                ])->values()->all(),
                'member_ids' => $channel->members->pluck('id')->values()->all(),
            ];
        })->values();

        return response()->json(['channels' => $payload]);
    }

    public function store(Request $request, ChannelAccess $access)
    {
        $workspace = $this->workspace($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]{0,59}$/'],
            'description' => ['nullable', 'string', 'max:200'],
            'private' => ['sometimes', 'boolean'],
            'member_ids' => ['array'],
            'member_ids.*' => ['integer'],
        ]);

        $type = ($data['private'] ?? false) ? Channel::PRIVATE : Channel::PUBLIC;
        $slug = $access->uniqueSlug($workspace, $data['name']);

        $channel = Channel::query()->create([
            'workspace_id' => $workspace->id,
            'name' => trim($data['name']),
            'slug' => $slug,
            'type' => $type,
            'description' => $data['description'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        $memberIds = collect($data['member_ids'] ?? [])
            ->push($request->user()->id)
            ->unique()
            ->all();

        $workspaceMemberIds = DB::table('workspace_members')
            ->where('workspace_id', $workspace->id)
            ->pluck('user_id');

        $memberIds = $type === Channel::PUBLIC
            ? $workspaceMemberIds->all()
            : $workspaceMemberIds->intersect($memberIds)->all();

        $channel->members()->sync(collect($memberIds)->mapWithKeys(fn ($id) => [
            $id => ['last_read_at' => now()],
        ])->all());

        return response()->json(['slug' => $channel->slug], 201);
    }

    public function update(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $model = $access->find($this->workspace($request), $channel);
        $access->assertCanView($model, $request->user());

        $data = $request->validate([
            'topic' => ['nullable', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:200'],
        ]);

        $model->update($data);

        return response()->json(['ok' => true, 'topic' => $model->topic, 'description' => $model->description]);
    }

    public function syncMembers(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $workspaceModel = $this->workspace($request);
        $model = $access->find($workspaceModel, $channel);
        abort_unless(in_array($model->type, [Channel::PRIVATE, Channel::ATTENDANCE], true), 422, 'Membership is managed on private channels.');

        if ($model->type === Channel::ATTENDANCE) {
            $this->requireManager($request);
        } else {
            $access->assertCanView($model, $request->user());
        }

        $data = $request->validate([
            'member_ids' => ['required', 'array'],
            'member_ids.*' => ['integer', Rule::exists('workspace_members', 'user_id')->where('workspace_id', $workspaceModel->id)],
        ]);

        $ids = collect($data['member_ids']);

        if ($model->type === Channel::ATTENDANCE) {
            $ids->push($workspaceModel->owner_id);
        } else {
            $ids->push($request->user()->id);
        }

        $model->members()->sync($ids->unique()->mapWithKeys(fn ($id) => [
            $id => ['last_read_at' => now()],
        ])->all());

        return response()->json(['member_ids' => $model->members()->pluck('users.id')->values()]);
    }

    public function markAllRead(Request $request, ChannelAccess $access)
    {
        $workspace = $this->workspace($request);
        $user = $request->user();

        $channels = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($user) {
                $query->where('type', Channel::PUBLIC)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->get();

        foreach ($channels as $channel) {
            $access->addMember($channel, $user);
            $channel->members()->updateExistingPivot($user->id, ['last_read_at' => now()]);
        }

        return response()->json(['ok' => true, 'read' => $channels->count()]);
    }

    public function markRead(Request $request, string $workspace, string $channel, ChannelAccess $access)
    {
        $model = $access->find($this->workspace($request), $channel);
        $access->assertCanView($model, $request->user());
        $access->addMember($model, $request->user());
        $model->members()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function direct(Request $request, ChannelAccess $access)
    {
        $workspace = $this->workspace($request);
        $data = $request->validate([
            'user_id' => ['required', 'integer'],
        ]);

        abort_if($data['user_id'] === $request->user()->id, 422, 'Pick someone else for a direct message.');

        $other = $workspace->members()->where('users.id', $data['user_id'])->first();
        abort_unless($other, 422, 'That person is not in this workspace.');

        $key = collect([$request->user()->id, $other->id])->sort()->implode(':');
        $channel = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where('dm_key', $key)
            ->first();

        if (! $channel) {
            $channel = Channel::query()->create([
                'workspace_id' => $workspace->id,
                'name' => 'dm',
                'slug' => $access->uniqueSlug($workspace, 'dm '.$key),
                'type' => Channel::DM,
                'created_by' => $request->user()->id,
                'dm_key' => $key,
            ]);
            $access->addMember($channel, $request->user());
            $access->addMember($channel, $other);
        }

        return response()->json(['slug' => $channel->slug]);
    }
}
