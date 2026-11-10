<?php

namespace App\Console\Commands;

use App\Models\Attachment;
use App\Models\Message;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class PruneHistory extends Command
{
    protected $signature = 'smackchat:prune-history';

    protected $description = 'Delete chat history older than 90 days for workspaces that have not kept the forever archive';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('smackchat.retention_days'));
        $standard = fn ($query) => $query->where('plan', 'standard');

        $oldRoots = Message::query()
            ->whereNull('parent_id')
            ->where('created_at', '<', $cutoff)
            ->whereHas('channel.workspace', $standard)
            ->pluck('id');

        $oldReplies = Message::query()
            ->whereNotNull('parent_id')
            ->where('created_at', '<', $cutoff)
            ->whereHas('channel.workspace', $standard)
            ->pluck('id');

        $cascadeReplies = $oldRoots->isEmpty()
            ? collect()
            : Message::query()->whereIn('parent_id', $oldRoots)->pluck('id');

        $messageIds = $oldRoots->merge($oldReplies)->merge($cascadeReplies)->unique()->values();
        $files = $this->forgetAttachmentFiles($messageIds);

        $roots = $oldRoots->isEmpty()
            ? 0
            : Message::query()->whereIn('id', $oldRoots)->delete();

        $replies = $oldReplies->isEmpty()
            ? 0
            : Message::query()->whereIn('id', $oldReplies)->delete();

        Message::query()
            ->whereNull('parent_id')
            ->where('reply_count', '>', 0)
            ->each(function (Message $parent) {
                $count = $parent->replies()->count();
                $parent->update([
                    'reply_count' => $count,
                    'last_reply_at' => $parent->replies()->max('created_at'),
                ]);
            });

        $this->info('Pruned '.$roots.' conversations, '.$replies.' older replies, and '.$files.' attachment files.');

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $messageIds
     */
    private function forgetAttachmentFiles($messageIds): int
    {
        if ($messageIds->isEmpty()) {
            return 0;
        }

        $removed = 0;

        Attachment::query()
            ->whereIn('message_id', $messageIds)
            ->get(['disk', 'path'])
            ->each(function (Attachment $attachment) use (&$removed) {
                if ($attachment->path === '' || $attachment->path === null) {
                    return;
                }

                if (Storage::disk($attachment->disk)->delete($attachment->path)) {
                    $removed++;
                }
            });

        return $removed;
    }
}
