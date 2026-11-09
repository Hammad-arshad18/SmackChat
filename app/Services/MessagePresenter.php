<?php

namespace App\Services;

use App\Models\Message;
use App\Models\User;
use App\Support\RichText;

class MessagePresenter
{
    public function __construct(private MessageCipher $cipher) {}

    public function present(Message $message, User $viewer): array
    {
        $deleted = $message->deleted_at !== null;
        $endToEnd = $message->cipher === 'e2e';
        $document = ($deleted || $endToEnd) ? null : $this->cipher->decrypt($message->ciphertext);
        $text = $document ? trim(RichText::plain($document)) : '';

        $reactions = $message->relationLoaded('reactions')
            ? $message->reactions
            : $message->reactions()->get();

        $attachments = $deleted
            ? collect()
            : ($message->relationLoaded('attachments')
                ? $message->attachments
                : $message->attachments()->get());

        return [
            'id' => $message->id,
            'channel_id' => $message->channel_id,
            'parent_id' => $message->parent_id,
            'user' => $message->user ? [
                'id' => $message->user->id,
                'name' => $message->user->name,
                'avatar_color' => $message->user->avatar_color,
                'title' => $message->user->title,
            ] : null,
            'document' => $document,
            'text' => $deleted ? '' : $text,
            'sealed' => ($deleted || ! $endToEnd) ? null : $message->ciphertext,
            'cipher' => $endToEnd ? 'e2e' : 'server',
            'excerpt' => $deleted
                ? 'Message deleted'
                : ($endToEnd
                    ? null
                    : ($text !== ''
                        ? mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? '', 0, 90, '…')
                        : ($attachments->isNotEmpty()
                            ? ($attachments->count() === 1 ? 'Sent an attachment' : 'Sent '.$attachments->count().' attachments')
                            : ''))),
            'system_type' => $message->system_type,
            'reply_count' => $message->reply_count,
            'last_reply_at' => optional($message->last_reply_at)?->toIso8601String(),
            'edited_at' => optional($message->edited_at)?->toIso8601String(),
            'deleted' => $deleted,
            'reactions' => collect($reactions)->groupBy('emoji')->map(function ($group) use ($viewer) {
                return [
                    'emoji' => $group->first()->emoji,
                    'count' => $group->count(),
                    'mine' => $group->contains('user_id', $viewer->id),
                ];
            })->values()->all(),
            'attachments' => collect($attachments)->map(fn ($attachment) => $attachment->present())->values()->all(),
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
