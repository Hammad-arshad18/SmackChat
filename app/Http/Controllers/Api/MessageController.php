<?php

namespace App\Http\Controllers\Api;

use App\Models\Channel;
use App\Models\Message;
use App\Services\AblyPublisher;
use App\Services\ChannelAccess;
use App\Services\MessageCipher;
use App\Services\MessagePresenter;
use App\Services\MessageWriter;
use App\Support\OpaqueId;
use App\Support\RichText;
use Illuminate\Http\Request;

class MessageController extends ApiController
{
    public function index(Request $request, string $workspace, string $channel, ChannelAccess $access, MessagePresenter $presenter)
    {
        $workspaceModel = $this->workspace($request);
        $model = $access->find($workspaceModel, $channel);
        $access->assertCanView($model, $request->user());
        $access->addMember($model, $request->user());

        $data = $request->validate([
            'parent_id' => ['nullable', 'integer'],
            'before' => ['nullable', 'integer'],
            'after' => ['nullable', 'integer'],
            'focus' => ['nullable', 'string', 'max:80'],
        ]);

        $focusId = OpaqueId::decode($data['focus'] ?? null);

        $query = Message::query()
            ->with(['user', 'reactions', 'attachments'])
            ->where('channel_id', $model->id)
            ->retained($workspaceModel);

        if ($focusId && empty($data['parent_id']) && empty($data['before']) && empty($data['after'])) {
            $target = (clone $query)->whereKey($focusId)->first();

            if ($target) {
                $anchorId = $target->parent_id ?: $target->id;
                $roots = (clone $query)->whereNull('parent_id');
                $older = (clone $roots)->where('id', '<', $anchorId)->orderByDesc('id')->limit(20)->get()->reverse()->values();
                $anchor = (clone $roots)->whereKey($anchorId)->first();
                $newer = (clone $roots)->where('id', '>', $anchorId)->orderBy('id')->limit(80)->get();
                $rows = $older->concat($anchor ? collect([$anchor]) : collect())->concat($newer)->values();
                $firstId = $rows->first()?->id;
                $model->members()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);

                return response()->json([
                    'messages' => $rows->map(fn (Message $message) => $presenter->present($message, $request->user()))->values(),
                    'has_more' => $firstId
                        ? (clone $roots)->where('id', '<', $firstId)->exists()
                        : false,
                    'focus_id' => $anchorId,
                ]);
            }
        }

        if (! empty($data['parent_id'])) {
            $parent = Message::query()
                ->where('channel_id', $model->id)
                ->whereKey($data['parent_id'])
                ->whereNull('parent_id')
                ->retained($workspaceModel)
                ->firstOrFail();
            $query->where('parent_id', $parent->id);
        } else {
            $query->whereNull('parent_id');
        }

        if (! empty($data['after'])) {
            $rows = $query->where('id', '>', $data['after'])->orderBy('id')->limit(100)->get();

            return response()->json([
                'messages' => $rows->map(fn (Message $message) => $presenter->present($message, $request->user()))->values(),
                'has_more' => false,
            ]);
        }

        if (! empty($data['before'])) {
            $query->where('id', '<', $data['before']);
        }

        $limit = 40;
        $rows = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit)->reverse()->values();

        if (empty($data['parent_id']) && empty($data['before'])) {
            $model->members()->updateExistingPivot($request->user()->id, ['last_read_at' => now()]);
        }

        return response()->json([
            'messages' => $rows->map(fn (Message $message) => $presenter->present($message, $request->user()))->values(),
            'has_more' => $hasMore,
        ]);
    }

    public function search(Request $request, ChannelAccess $access, MessageCipher $cipher, MessagePresenter $presenter)
    {
        $workspace = $this->workspace($request);
        $user = $request->user();
        $data = $request->validate([
            'channel' => ['nullable', 'string', 'max:120'],
        ]);

        $channels = Channel::query()
            ->with('members:id,name,avatar_color')
            ->where('workspace_id', $workspace->id)
            ->where(function ($query) use ($user) {
                $query->where('type', Channel::PUBLIC)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->when($data['channel'] ?? null, fn ($query, $slug) => $query->where('slug', $slug))
            ->get()
            ->keyBy('id');

        if ($channels->isEmpty()) {
            return response()->json(['results' => []]);
        }

        if (! empty($data['channel'])) {
            $access->assertCanView($channels->first(), $user);
        }

        $rows = Message::query()
            ->with(['user', 'reactions', 'attachments'])
            ->whereIn('channel_id', $channels->keys())
            ->whereNull('deleted_at')
            ->retained($workspace)
            ->orderByDesc('id')
            ->limit(250)
            ->get();

        $results = [];

        foreach ($rows as $message) {
            $channel = $channels->get($message->channel_id);
            $snippet = null;

            if ($message->cipher !== 'e2e') {
                $document = $cipher->decrypt($message->ciphertext);
                $text = $document ? trim(RichText::plain($document)) : '';

                if ($text === '') {
                    continue;
                }

                $snippet = mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? '', 0, 150, '…');
            }

            $others = $channel->members->where('id', '!=', $user->id)->values();

            $results[] = [
                'message' => $presenter->present($message, $user),
                'channel' => [
                    'id' => $channel->id,
                    'slug' => $channel->slug,
                    'type' => $channel->type,
                    'label' => $channel->type === Channel::DM
                        ? ($others->first()?->name ?? 'Direct message')
                        : $channel->name,
                ],
                'snippet' => $snippet,
            ];

            if (count($results) >= 200) {
                break;
            }
        }

        return response()->json(['results' => $results]);
    }

    public function store(Request $request, string $workspace, string $channel, ChannelAccess $access, MessageWriter $writer)
    {
        $model = $access->find($this->workspace($request), $channel);
        $data = $request->validate([
            'sealed' => ['required', 'string', 'max:64000'],
            'parent_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer'],
        ]);

        $payload = $writer->postSealed(
            $model,
            $request->user(),
            $data['sealed'],
            $data['parent_id'] ?? null,
            $data['attachment_ids'] ?? []
        );

        return response()->json($payload, 201);
    }

    public function update(Request $request, string $workspace, Message $message, ChannelAccess $access, MessageWriter $writer)
    {
        $this->guardMessage($request, $message, $access);
        $data = $request->validate(['sealed' => ['required', 'string', 'max:64000']]);

        return response()->json($writer->editSealed($message, $request->user(), $data['sealed']));
    }

    public function destroy(Request $request, string $workspace, Message $message, ChannelAccess $access, MessageWriter $writer)
    {
        $this->guardMessage($request, $message, $access);

        return response()->json($writer->delete(
            $message,
            $request->user(),
            $this->membership($request)->managesOffice()
        ));
    }

    public function react(Request $request, string $workspace, Message $message, ChannelAccess $access, AblyPublisher $ably)
    {
        $this->guardMessage($request, $message, $access);
        abort_if($message->deleted_at !== null, 422, 'Deleted messages cannot take reactions.');

        $data = $request->validate([
            'emoji' => ['required', 'string', 'max:16'],
        ]);

        $existing = $message->reactions()
            ->where('user_id', $request->user()->id)
            ->where('emoji', $data['emoji'])
            ->first();

        if ($existing) {
            $existing->delete();
        } else {
            $message->reactions()->create([
                'user_id' => $request->user()->id,
                'emoji' => $data['emoji'],
            ]);
        }

        $message->load(['user', 'reactions', 'attachments', 'channel']);
        $presenter = app(MessagePresenter::class);
        $payload = [
            'event' => 'message.reacted',
            'actor_id' => $request->user()->id,
            'message' => $presenter->present($message, $request->user()),
        ];
        $ably->publish($message->channel->workspace_id, $message->channel_id, $payload);

        return response()->json($payload);
    }

    private function guardMessage(Request $request, Message $message, ChannelAccess $access): void
    {
        $workspace = $this->workspace($request);
        $message->loadMissing('channel');
        abort_unless($message->channel && $message->channel->workspace_id === $workspace->id, 404);
        $access->assertCanView($message->channel, $request->user());

        if (! $workspace->retainsForever() && $message->created_at->lt(now()->subDays(config('smackchat.retention_days')))) {
            abort(404);
        }
    }
}
