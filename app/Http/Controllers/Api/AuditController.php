<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditEvent;
use Illuminate\Http\Request;

class AuditController extends ApiController
{
    public function index(Request $request)
    {
        $this->requireManager($request);
        $data = $request->validate([
            'before' => ['nullable', 'integer', 'min:1'],
            'action' => ['nullable', 'string', 'max:80'],
        ]);

        $events = AuditEvent::query()
            ->with('actor:id,name')
            ->where('workspace_id', $this->workspace($request)->id)
            ->when($data['action'] ?? null, fn ($query, $action) => $query->where('action', $action))
            ->when($data['before'] ?? null, fn ($query, $before) => $query->where('id', '<', $before))
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (AuditEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'actor' => $event->actor ? [
                    'id' => $event->actor->id,
                    'name' => $event->actor->name,
                ] : null,
                'subject_type' => $event->subject_type,
                'subject_id' => $event->subject_id,
                'metadata' => $event->metadata,
                'ip' => $event->ip,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values();

        return response()->json(['events' => $events]);
    }
}
