<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function workspace(Request $request): Workspace
    {
        return $request->attributes->get('workspace');
    }

    protected function membership(Request $request): WorkspaceMember
    {
        return $request->attributes->get('membership');
    }

    protected function requireManager(Request $request): void
    {
        abort_unless($this->membership($request)->managesOffice(), 403, 'Only the owner or an admin can do that.');
    }

    protected function requireOwner(Request $request): void
    {
        abort_unless($this->membership($request)->role === WorkspaceMember::OWNER, 403, 'Only the workspace owner can do that.');
    }

    protected function userPayload(User $user): array
    {
        $current = $user->currentWorkspace;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'title' => $user->title,
            'timezone' => $user->timezone,
            'avatar_color' => $user->avatar_color,
            'status_text' => $user->status_text,
            'status_emoji' => $user->status_emoji,
            'last_seen_at' => optional($user->last_seen_at)?->toIso8601String(),
            'current_workspace' => $current ? [
                'name' => $current->name,
                'slug' => $current->slug,
            ] : null,
            'workspaces' => $user->workspaces()->orderBy('name')->get()->map(fn (Workspace $workspace) => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'role' => $workspace->pivot->role,
            ])->values()->all(),
        ];
    }
}
