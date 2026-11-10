<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkspaceMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $request->route('workspace');
        $workspace = $workspace instanceof Workspace
            ? $workspace
            : Workspace::query()->where('slug', $workspace)->firstOrFail();
        $user = $request->user();

        $membership = WorkspaceMember::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->first();

        abort_unless($membership, 403, 'You are not a member of this workspace.');

        $request->attributes->set('workspace', $workspace);
        $request->attributes->set('membership', $membership);

        $updates = [];
        if ($user->current_workspace_id !== $workspace->id) {
            $updates['current_workspace_id'] = $workspace->id;
        }
        if (! $user->last_seen_at || $user->last_seen_at->lt(now()->subMinute())) {
            $updates['last_seen_at'] = now();
        }
        if (! empty($updates)) {
            $user->forceFill($updates)->saveQuietly();
        }

        return $next($request);
    }
}
