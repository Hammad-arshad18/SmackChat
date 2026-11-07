<?php

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;

class PruneHistory extends Command
{
    protected $signature = 'smackchat:prune-history';

    protected $description = 'Delete chat history older than 90 days for workspaces that have not kept the forever archive';

    public function handle(): int
    {
        $cutoff = now()->subDays(config('smackchat.retention_days'));

        $roots = Message::query()
            ->whereNull('parent_id')
            ->where('created_at', '<', $cutoff)
            ->whereHas('channel.workspace', fn ($query) => $query->where('plan', 'standard'))
            ->delete();

        $replies = Message::query()
            ->where('created_at', '<', $cutoff)
            ->whereHas('channel.workspace', fn ($query) => $query->where('plan', 'standard'))
            ->delete();

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

        $this->info('Pruned '.$roots.' conversations and '.$replies.' older replies.');

        return self::SUCCESS;
    }
}
