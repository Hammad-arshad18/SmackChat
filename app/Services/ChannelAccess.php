<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class ChannelAccess
{
    public function find(Workspace $workspace, string $slug): Channel
    {
        return Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function assertCanView(Channel $channel, User $user): void
    {
        if ($channel->isOpen()) {
            return;
        }

        $allowed = $channel->members()->where('user_id', $user->id)->exists();
        abort_unless($allowed, 403, 'That conversation is private.');
    }

    public function uniqueSlug(Workspace $workspace, string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'channel';
        $slug = $base;
        $suffix = 2;

        while (
            Channel::query()
                ->where('workspace_id', $workspace->id)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }

    public function addMember(Channel $channel, User $user): void
    {
        $channel->members()->syncWithoutDetaching([$user->id]);
    }
}
