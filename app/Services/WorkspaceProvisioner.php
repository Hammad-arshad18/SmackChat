<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Support\RichText;
use Illuminate\Support\Str;

class WorkspaceProvisioner
{
    public function __construct(
        private ChannelAccess $access,
        private MessageWriter $messages,
        private AblyPublisher $ably,
    ) {}

    public function create(User $owner, string $name, ?string $timezone = null): Workspace
    {
        $timezone = $timezone ?: ($owner->timezone ?: 'UTC');

        $workspace = Workspace::query()->create([
            'name' => $name,
            'slug' => $this->slug($name),
            'owner_id' => $owner->id,
            'timezone' => $timezone,
            'work_start' => '09:00:00',
            'work_end' => '17:00:00',
            'break_allowance_minutes' => 0,
            'work_days' => [1, 2, 3, 4, 5],
            'plan' => 'standard',
        ]);

        $workspace->members()->attach($owner->id, ['role' => WorkspaceMember::OWNER]);
        $owner->forceFill([
            'current_workspace_id' => $workspace->id,
            'timezone' => $owner->timezone ?: $timezone,
        ])->save();

        $general = Channel::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'general',
            'slug' => 'general',
            'type' => Channel::PUBLIC,
            'description' => 'Company-wide conversation',
            'topic' => 'Say hello and post what the whole office should see.',
            'created_by' => $owner->id,
        ]);
        $this->access->addMember($general, $owner);

        $timesheets = Channel::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'timesheets',
            'slug' => 'timesheets',
            'type' => Channel::ATTENDANCE,
            'description' => 'Private checkout reports for the people who run the floor.',
            'topic' => 'Every checkout lands here with the day\'s hours.',
            'created_by' => $owner->id,
        ]);
        $this->access->addMember($timesheets, $owner);

        $this->messages->post(
            $general,
            $owner,
            RichText::paragraph('Welcome to '.$workspace->name.'. Channels are for the team, direct messages stay between two people, and every checkout writes the day\'s hours into Timesheets.'),
            null,
            'welcome'
        );

        return $workspace->fresh(['channels']);
    }

    public function admit(Workspace $workspace, User $user, string $role): void
    {
        $workspace->members()->syncWithoutDetaching([
            $user->id => ['role' => $role],
        ]);

        if (! $user->current_workspace_id) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }

        $publicChannels = $workspace->channels()->where('type', Channel::PUBLIC)->get();

        foreach ($publicChannels as $channel) {
            $this->access->addMember($channel, $user);
        }

        $this->ably->publishWorkspace($workspace->id, 'member.joined', [
            'user_id' => $user->id,
            'name' => $user->name,
            'role' => $role,
        ]);
    }

    private function slug(string $name): string
    {
        $base = Str::slug($name);
        $base = $base !== '' ? $base : 'workspace';
        $slug = $base;
        $suffix = 2;

        while (Workspace::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
