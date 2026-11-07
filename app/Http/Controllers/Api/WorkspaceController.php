<?php

namespace App\Http\Controllers\Api;

use App\Services\WorkspaceProvisioner;
use Illuminate\Http\Request;

class WorkspaceController extends ApiController
{
    public function index(Request $request)
    {
        return response()->json([
            'workspaces' => $this->userPayload($request->user())['workspaces'],
        ]);
    }

    public function store(Request $request, WorkspaceProvisioner $provisioner)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'timezone' => ['nullable', 'timezone:all'],
        ]);

        $workspace = $provisioner->create(
            $request->user(),
            $data['name'],
            $data['timezone'] ?? $request->user()->timezone
        );

        return response()->json(['slug' => $workspace->slug], 201);
    }

    public function show(Request $request)
    {
        $workspace = $this->workspace($request);
        $seats = $workspace->members()->count();

        return response()->json([
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'timezone' => $workspace->timezone,
                'plan' => $workspace->plan,
                'role' => $this->membership($request)->role,
                'retention_days' => config('smackchat.retention_days'),
                'archive_price_cents' => config('smackchat.archive_price_cents'),
                'seats' => $seats,
                'monthly_cents' => $seats * config('smackchat.archive_price_cents'),
                'work_start' => $workspace->clockLabel($workspace->work_start),
                'work_end' => $workspace->clockLabel($workspace->work_end),
                'work_days' => $workspace->work_days,
                'location_restricted' => (bool) $workspace->location_restricted,
                'location_latitude' => $workspace->location_latitude,
                'location_longitude' => $workspace->location_longitude,
                'location_radius_m' => (int) ($workspace->location_radius_m ?? 250),
                'plan_activated_at' => optional($workspace->plan_activated_at)?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->requireManager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'timezone:all'],
        ]);

        $this->workspace($request)->update($data);

        return $this->show($request);
    }
}
