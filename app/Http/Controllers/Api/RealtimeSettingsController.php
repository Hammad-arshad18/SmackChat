<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use App\Services\AblyPublisher;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

/**
 * The workspace can bring its own Ably app instead of sharing the server key.
 * The key is a secret: only the owner sees or changes it, and it never leaves
 * the API in readable form.
 */
class RealtimeSettingsController extends ApiController
{
    public function update(Request $request, AuditLogger $audit)
    {
        $this->requireOwner($request);

        $data = $request->validate([
            'ably_key' => ['required', 'string', 'min:12', 'max:255', 'regex:/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+:[A-Za-z0-9_-]+$/'],
        ]);

        $workspace = $this->workspace($request);
        $workspace->forceFill(['ably_key' => trim($data['ably_key'])])->save();

        $audit->record('workspace.ably_key_changed', $workspace, $request->user(), [], $workspace, $request);

        return $this->payload($workspace);
    }

    public function destroy(Request $request, AuditLogger $audit)
    {
        $this->requireOwner($request);

        $workspace = $this->workspace($request);
        $workspace->forceFill(['ably_key' => null])->save();

        $audit->record('workspace.ably_key_removed', $workspace, $request->user(), [], $workspace, $request);

        return $this->payload($workspace);
    }

    private function payload(Workspace $workspace)
    {
        $status = app(AblyPublisher::class)->status($workspace);

        return response()->json([
            'realtime' => $status,
            'workspace' => [
                'slug' => $workspace->slug,
                'realtime' => $status,
            ],
        ]);
    }
}
