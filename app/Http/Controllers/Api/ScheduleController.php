<?php

namespace App\Http\Controllers\Api;

use App\Models\Holiday;
use App\Services\ScheduleInspector;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ScheduleController extends ApiController
{
    public function show(Request $request, ScheduleInspector $inspector)
    {
        $workspace = $this->workspace($request);
        $timesheet = $workspace->channels()->where('type', 'attendance')->with('members:id,name,avatar_color')->first();

        return response()->json([
            'schedule' => [
                'timezone' => $workspace->timezone,
                'work_start' => $workspace->clockLabel($workspace->work_start),
                'work_end' => $workspace->clockLabel($workspace->work_end),
                'work_days' => array_map('intval', $workspace->work_days ?? []),
                'location_restricted' => (bool) $workspace->location_restricted,
                'location_latitude' => $workspace->location_latitude,
                'location_longitude' => $workspace->location_longitude,
                'location_radius_m' => (int) ($workspace->location_radius_m ?? 250),
                'today' => $inspector->describe($workspace, now()),
                'holidays' => $workspace->holidays()->orderBy('observed_on')->get()->map(fn (Holiday $holiday) => [
                    'id' => $holiday->id,
                    'name' => $holiday->name,
                    'observed_on' => $holiday->observed_on->toDateString(),
                    'repeats_yearly' => $holiday->repeats_yearly,
                ]),
                'timesheet' => $timesheet ? [
                    'slug' => $timesheet->slug,
                    'members' => $timesheet->members->map(fn ($user) => [
                        'id' => $user->id,
                        'name' => $user->name,
                        'avatar_color' => $user->avatar_color,
                    ])->values(),
                ] : null,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $this->requireManager($request);
        $data = $request->validate([
            'timezone' => ['required', 'timezone:all'],
            'work_start' => ['required', 'date_format:H:i'],
            'work_end' => ['required', 'date_format:H:i', 'after:work_start'],
            'work_days' => ['required', 'array', 'min:1'],
            'work_days.*' => ['integer', Rule::in([1, 2, 3, 4, 5, 6, 7])],
            'location_restricted' => ['sometimes', 'boolean'],
            'location_latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'location_longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'location_radius_m' => ['nullable', 'integer', 'min:25', 'max:100000'],
        ]);

        $workspace = $this->workspace($request);
        $updates = [
            'timezone' => $data['timezone'],
            'work_start' => $data['work_start'].':00',
            'work_end' => $data['work_end'].':00',
            'work_days' => array_values(array_unique(array_map('intval', $data['work_days']))),
        ];

        if (array_key_exists('location_restricted', $data)
            || array_key_exists('location_latitude', $data)
            || array_key_exists('location_longitude', $data)
            || array_key_exists('location_radius_m', $data)) {
            $restricted = array_key_exists('location_restricted', $data)
                ? (bool) $data['location_restricted']
                : (bool) $workspace->location_restricted;
            $latitude = array_key_exists('location_latitude', $data)
                ? $data['location_latitude']
                : $workspace->location_latitude;
            $longitude = array_key_exists('location_longitude', $data)
                ? $data['location_longitude']
                : $workspace->location_longitude;
            $radius = array_key_exists('location_radius_m', $data)
                ? (int) $data['location_radius_m']
                : (int) ($workspace->location_radius_m ?? 250);

            if ($restricted && ($latitude === null || $longitude === null)) {
                return response()->json([
                    'message' => 'Set the approved latitude and longitude before enabling the location restriction.',
                    'errors' => ['location_latitude' => ['Latitude and longitude are required when location restriction is enabled.']],
                ], 422);
            }

            $updates['location_restricted'] = $restricted;
            $updates['location_latitude'] = $latitude;
            $updates['location_longitude'] = $longitude;
            $updates['location_radius_m'] = $radius;
        }

        $workspace->update($updates);

        return $this->show($request, app(ScheduleInspector::class));
    }

    public function storeHoliday(Request $request)
    {
        $this->requireManager($request);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'observed_on' => ['required', 'date'],
            'repeats_yearly' => ['sometimes', 'boolean'],
        ]);

        $holiday = $this->workspace($request)->holidays()->create([
            'name' => $data['name'],
            'observed_on' => $data['observed_on'],
            'repeats_yearly' => (bool) ($data['repeats_yearly'] ?? false),
        ]);

        return response()->json([
            'holiday' => [
                'id' => $holiday->id,
                'name' => $holiday->name,
                'observed_on' => $holiday->observed_on->toDateString(),
                'repeats_yearly' => $holiday->repeats_yearly,
            ],
        ], 201);
    }

    public function destroyHoliday(Request $request, string $workspace, Holiday $holiday)
    {
        $this->requireManager($request);
        abort_unless($holiday->workspace_id === $this->workspace($request)->id, 404);
        $holiday->delete();

        return response()->json(['ok' => true]);
    }
}
