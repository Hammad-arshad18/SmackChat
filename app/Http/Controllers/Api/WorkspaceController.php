<?php

namespace App\Http\Controllers\Api;

use App\Models\Workspace;
use App\Services\WorkspaceProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                'logo_url' => $workspace->logoUrl(),
                'timezone' => $workspace->timezone,
                'plan' => $workspace->plan,
                'role' => $this->membership($request)->role,
                'standard_day_minutes' => $workspace->standardDayMinutes(),
                'break_allowance_minutes' => (int) ($workspace->break_allowance_minutes ?? 0),
                'window_minutes' => $workspace->windowMinutes(),
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
                'location_allowed_ips' => array_values($workspace->location_allowed_ips ?? []),
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

    public function updateLogo(Request $request)
    {
        $this->requireManager($request);
        $data = $request->validate([
            'logo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $workspace = $this->workspace($request);
        $this->deleteLogo($workspace);
        $file = $data['logo'];
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->guessExtension() ?: 'jpg');
        $path = $file->storeAs('logos/'.$workspace->id, Str::uuid()->toString().'.'.$extension, 'local');
        $workspace->forceFill(['logo_path' => $path])->save();

        return $this->show($request);
    }

    public function destroyLogo(Request $request)
    {
        $this->requireManager($request);
        $workspace = $this->workspace($request);
        $this->deleteLogo($workspace);
        $workspace->forceFill(['logo_path' => null])->save();

        return $this->show($request);
    }

    public function logo(Request $request)
    {
        $workspace = $this->workspace($request);
        abort_unless($workspace->logo_path && Storage::disk('local')->exists($workspace->logo_path), 404);

        $etag = '"'.sha1($workspace->id.'|'.$workspace->logo_path.'|'.$workspace->updated_at).'"';

        if ($request->headers->get('If-None-Match') === $etag) {
            return response('', 304, [
                'ETag' => $etag,
                'Cache-Control' => 'private, max-age=604800',
            ]);
        }

        return Storage::disk('local')->response($workspace->logo_path, null, [
            'Cache-Control' => 'private, max-age=604800',
            'ETag' => $etag,
        ]);
    }

    private function deleteLogo(Workspace $workspace): void
    {
        if ($workspace->logo_path) {
            Storage::disk('local')->delete($workspace->logo_path);
        }
    }
}
