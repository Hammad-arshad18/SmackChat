<?php

namespace App\Http\Controllers\Api;

use App\Services\AuditLogger;
use Illuminate\Http\Request;

class BillingController extends ApiController
{
    public function archive(Request $request, AuditLogger $audit)
    {
        $this->requireOwner($request);
        $workspace = $this->workspace($request);
        $seats = $workspace->members()->count();
        $cents = $seats * (int) config('smackchat.archive_price_cents');
        $previous = $workspace->plan;

        $workspace->update([
            'plan' => 'archive',
            'plan_seats' => $seats,
            'plan_activated_at' => now(),
        ]);

        $audit->record('billing.plan_changed', $workspace, $request->user(), [
            'from' => $previous,
            'to' => 'archive',
            'seats' => $seats,
            'monthly_cents' => $cents,
        ], null, $request);

        return response()->json([
            'plan' => 'archive',
            'seats' => $seats,
            'monthly_cents' => $cents,
            'message' => 'Forever history is on for '.$seats.' people at $'.number_format($cents / 100, 2).' per month.',
        ]);
    }

    public function standard(Request $request, AuditLogger $audit)
    {
        $this->requireOwner($request);
        $workspace = $this->workspace($request);
        $previous = $workspace->plan;
        $workspace->update(['plan' => 'standard']);

        $audit->record('billing.plan_changed', $workspace, $request->user(), [
            'from' => $previous,
            'to' => 'standard',
        ], null, $request);

        return response()->json([
            'plan' => 'standard',
            'message' => 'History will again be kept for '.config('smackchat.retention_days').' days.',
        ]);
    }
}
