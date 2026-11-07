<?php

namespace App\Http\Controllers\Api;

use App\Models\Channel;
use App\Models\Message;
use App\Services\ChannelAccess;
use App\Services\MessageCipher;
use App\Services\MessagePresenter;
use App\Services\MessageWriter;
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
        ]);

        $query = Message::query()
            ->with(['user', 'reactions', 'attachments'])
            ->where('channel_id', $model->id)
            ->retained($workspaceModel);

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
            'q' => ['required', 'string', 'min:2', 'max:100'],
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

        $needle = mb_strtolower($data['q']);
        $rows = Message::query()
            ->with(['user', 'reactions', 'attachments'])
            ->whereIn('channel_id', $channels->keys())
            ->whereNull('deleted_at')
            ->retained($workspace)
            ->orderByDesc('id')
            ->limit(800)
            ->get();

        $results = [];

        foreach ($rows as $message) {
            $document = $cipher->decrypt($message->ciphertext);
            $text = $document ? trim(RichText::plain($document)) : '';
            $position = mb_stripos(mb_strtolower($text), $needle);

            if ($text === '' || $position === false) {
                continue;
            }

            $channel = $channels->get($message->channel_id);
            $start = max(0, $position - 46);
            $snippet = ($start > 0 ? '…' : '').mb_substr($text, $start, 150).(mb_strlen($text) > $start + 150 ? '…' : '');
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

            if (count($results) >= 60) {
                break;
            }
        }

        return response()->json(['results' => $results]);
    }

    public function store(Request $request, string $workspace, string $channel, ChannelAccess $access, MessageWriter $writer)
    {
        $model = $access->find($this->workspace($request), $channel);
        $data = $request->validate([
            'document' => ['required', 'array'],
            'parent_id' => ['nullable', 'integer'],
            'attachment_ids' => ['nullable', 'array', 'max:10'],
            'attachment_ids.*' => ['integer'],
        ]);

        $payload = $writer->post(
            $model,
            $request->user(),
            $data['document'],
            $data['parent_id'] ?? null,
            null,
            false,
            $data['attachment_ids'] ?? []
        );

        return response()->json($payload, 201);
    }

    public function update(Request $request, string $workspace, Message $message, ChannelAccess $access, MessageWriter $writer)
    {
        $this->guardMessage($request, $message, $access);
        $data = $request->validate(['document' => ['required', 'array']]);

        return response()->json($writer->edit($message, $request->user(), $data['document']));
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

    public function react(Request $request, string $workspace, Message $message, ChannelAccess $access)
    {
        $this->guardMessage($request, $message, $access);
        abort_if($message->deleted_at !== null, 422, 'Deleted messages cannot take reactions.');

        $allowed = ['👍', '❤️', '😄', '🎉', '✅', '👀', '🚀', '🔥'];
        $data = $request->validate([
            'emoji' => ['required', 'string', 'in:'.implode(',', $allowed)],
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

        $message->load(['user', 'reactions', 'attachments']);
        $presenter = app(\App\Services\MessagePresenter::class);

        return response()->json([
            'message' => $presenter->present($message, $request->user()),
        ]);
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
