<?php

namespace App\Services;

use App\Models\Attachment;
use App\Models\Channel;
use App\Models\Message;
use App\Models\User;
use App\Support\RichText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class MessageWriter
{
    public function __construct(
        private MessageCipher $cipher,
        private MessagePresenter $presenter,
        private AblyPublisher $ably,
        private ChannelAccess $access,
    ) {}

    public function post(
        Channel $channel,
        User $author,
        array $document,
        ?int $parentId = null,
        ?string $systemType = null,
        bool $internal = false,
        array $attachmentIds = [],
    ): array {
        if (! $internal) {
            $this->access->assertCanView($channel, $author);
        }
        $document = RichText::document($document, ! empty($attachmentIds));
        $parent = $this->parent($channel, $parentId);

        $message = DB::transaction(function () use ($channel, $author, $document, $parent, $systemType, $attachmentIds) {
            $message = Message::query()->create([
                'channel_id' => $channel->id,
                'user_id' => $author->id,
                'parent_id' => $parent?->id,
                'ciphertext' => $this->cipher->encrypt($document),
                'system_type' => $systemType,
            ]);

            if (! empty($attachmentIds)) {
                $ids = collect($attachmentIds)->map(fn ($id) => (int) $id)->unique()->take(10)->values();
                $attached = Attachment::query()
                    ->where('channel_id', $channel->id)
                    ->where('user_id', $author->id)
                    ->whereNull('message_id')
                    ->whereIn('id', $ids)
                    ->count();

                if ($attached !== $ids->count()) {
                    throw ValidationException::withMessages([
                        'attachment_ids' => 'One of those attachments is no longer available. Please upload it again.',
                    ]);
                }

                Attachment::query()->whereIn('id', $ids)->update(['message_id' => $message->id]);
            }

            if ($parent) {
                $parent->update([
                    'reply_count' => $parent->replies()->count(),
                    'last_reply_at' => $message->created_at,
                ]);
            }

            return $message;
        });

        $message->load(['user', 'reactions', 'attachments']);
        $payload = [
            'event' => 'message.created',
            'message' => $this->presenter->present($message, $author),
            'parent' => $parent ? [
                'id' => $parent->id,
                'reply_count' => $parent->fresh()->reply_count,
                'last_reply_at' => optional($parent->fresh()->last_reply_at)?->toIso8601String(),
            ] : null,
        ];

        $this->ably->publish($channel->workspace_id, $channel->id, $payload);

        try {
            app(WebPushNotifier::class)->notify(
                $channel,
                $author,
                $message,
                (string) ($payload['message']['excerpt'] ?? ''),
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $payload;
    }

    public function edit(Message $message, User $editor, array $document): array
    {
        $this->guardAuthor($message, $editor);
        abort_if($message->system_type !== null, 422, 'System posts stay as they were written.');
        $document = RichText::document($document);

        $message->update([
            'ciphertext' => $this->cipher->encrypt($document),
            'edited_at' => now(),
        ]);

        $message->load(['user', 'reactions', 'attachments']);
        $payload = [
            'event' => 'message.updated',
            'message' => $this->presenter->present($message, $editor),
        ];
        $this->ably->publish($message->channel->workspace_id, $message->channel_id, $payload);

        return $payload;
    }

    public function delete(Message $message, User $actor, bool $asManager): array
    {
        $owns = $message->user_id === $actor->id;
        abort_unless($owns || $asManager, 403, 'Only the sender or an admin can delete this message.');
        abort_if($message->deleted_at !== null, 422, 'That message is already deleted.');

        $message->update([
            'ciphertext' => $this->cipher->encrypt(RichText::paragraph('Message deleted')),
            'deleted_at' => now(),
        ]);

        if ($message->parent_id) {
            $parent = $message->parent;
            $parent?->update([
                'reply_count' => $parent->replies()->whereNull('deleted_at')->count(),
            ]);
        }

        $message->load(['user', 'reactions', 'attachments']);
        $payload = [
            'event' => 'message.updated',
            'message' => $this->presenter->present($message, $actor),
        ];
        $this->ably->publish($message->channel->workspace_id, $message->channel_id, $payload);

        return $payload;
    }

    private function parent(Channel $channel, ?int $parentId): ?Message
    {
        if ($parentId === null) {
            return null;
        }

        $parent = Message::query()
            ->where('channel_id', $channel->id)
            ->whereKey($parentId)
            ->first();

        if (! $parent || $parent->parent_id !== null || $parent->deleted_at !== null) {
            throw ValidationException::withMessages([
                'parent_id' => 'Reply to the original message in the thread.',
            ]);
        }

        if (! $channel->workspace->retainsForever() && $parent->created_at->lt(now()->subDays(config('smackchat.retention_days')))) {
            throw ValidationException::withMessages([
                'parent_id' => 'That thread has aged out of the 90-day history.',
            ]);
        }

        return $parent;
    }

    private function guardAuthor(Message $message, User $editor): void
    {
        abort_if($message->deleted_at !== null, 422, 'That message was deleted.');
        abort_unless($message->user_id === $editor->id, 403, 'You can only edit your own messages.');
    }
}
