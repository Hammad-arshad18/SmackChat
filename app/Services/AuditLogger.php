<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Append-only record of actions HR, IT, or an auditor may need to explain:
 * role changes, removals, schedule/presence edits, plan changes, exports, and
 * message deletions by a manager.
 */
class AuditLogger
{
    public function record(
        string $action,
        ?Workspace $workspace = null,
        ?User $actor = null,
        array $metadata = [],
        ?Model $subject = null,
        ?Request $request = null,
    ): void {
        AuditEvent::query()->create([
            'workspace_id' => $workspace?->id,
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject ? class_basename($subject) : null,
            'subject_id' => $subject?->getKey(),
            'metadata' => $metadata === [] ? null : $metadata,
            'ip' => ($request ?? request())->ip(),
        ]);
    }
}
