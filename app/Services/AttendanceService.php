<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\AttendanceEvent;
use App\Models\Channel;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Duration;
use App\Support\OfficeNetwork;
use App\Support\RichText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public function __construct(
        private AttendanceCalculator $calculator,
        private ScheduleInspector $inspector,
        private MessageWriter $messages,
    ) {}

    public function today(Workspace $workspace, User $user): ?AttendanceDay
    {
        $open = AttendanceDay::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['open', 'on_break'])
            ->latest('work_date')
            ->first();

        if ($open) {
            return $open->load('events');
        }

        $date = now()->timezone($workspace->timezone)->toDateString();

        return AttendanceDay::query()
            ->with('events')
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $user->id)
            ->whereDate('work_date', $date)
            ->first();
    }

    public function punch(Workspace $workspace, User $user, string $action, ?array $location = null, ?string $clientIp = null): array
    {
        return DB::transaction(function () use ($workspace, $user, $action, $location, $clientIp) {
            $day = $this->today($workspace, $user);
            $now = now();

            if ($action === 'check_in') {
                $presence = $this->requirePresence($workspace, $location, $clientIp, 'check in');

                if ($day && in_array($day->status, ['open', 'on_break', 'completed'], true) && $day->work_date->toDateString() === $now->timezone($workspace->timezone)->toDateString()) {
                    throw ValidationException::withMessages([
                        'action' => $day->status === 'completed'
                            ? 'You already finished today\'s shift.'
                            : 'You are already checked in.',
                    ]);
                }

                if ($day && in_array($day->status, ['open', 'on_break'], true)) {
                    throw ValidationException::withMessages([
                        'action' => 'Check out of the open shift before starting another.',
                    ]);
                }

                $context = $this->inspector->describe($workspace, $now);
                $day = AttendanceDay::query()->create([
                    'workspace_id' => $workspace->id,
                    'user_id' => $user->id,
                    'work_date' => $context['date'],
                    'check_in_at' => $now,
                    'check_in_latitude' => $presence['latitude'] ?? null,
                    'check_in_longitude' => $presence['longitude'] ?? null,
                    'check_in_ip' => $presence['ip'] ?? $this->safeIp($clientIp),
                    'status' => 'open',
                    'outside_hours' => $context['outside_hours'],
                    'non_working_day' => $context['non_working_day'],
                    'holiday_name' => $context['holiday'],
                ]);
                $this->event($day, 'check_in', $now);

                return ['day' => $day->load('events'), 'message' => null];
            }

            if (! $day || $day->status === 'completed') {
                throw ValidationException::withMessages([
                    'action' => 'Check in before you record a break or checkout.',
                ]);
            }

            if ($action === 'break_in') {
                if ($day->status !== 'open') {
                    throw ValidationException::withMessages(['action' => 'You are already on a break.']);
                }
                $day->update(['status' => 'on_break']);
                $this->event($day, 'break_in', $now);

                return ['day' => $day->fresh('events'), 'message' => null];
            }

            if ($action === 'break_out') {
                if ($day->status !== 'on_break') {
                    throw ValidationException::withMessages(['action' => 'Start a break before ending one.']);
                }
                $day->update(['status' => 'open']);
                $this->event($day, 'break_out', $now);

                return ['day' => $day->fresh('events'), 'message' => null];
            }

            if ($action !== 'check_out') {
                throw ValidationException::withMessages(['action' => 'Unknown attendance action.']);
            }

            $presence = $this->requirePresence($workspace, $location, $clientIp, 'check out');
            $this->event($day, 'check_out', $now);
            $totals = $this->calculator->summarize($day->events()->get(), $now);
            $checkoutContext = $this->inspector->describe($workspace, $now);
            $day->update([
                'check_out_at' => $now,
                'check_out_latitude' => $presence['latitude'] ?? null,
                'check_out_longitude' => $presence['longitude'] ?? null,
                'check_out_ip' => $presence['ip'] ?? $this->safeIp($clientIp),
                'status' => 'completed',
                'work_minutes' => $totals['work_minutes'],
                'break_minutes' => $totals['break_minutes'],
                'outside_hours' => $day->outside_hours || $checkoutContext['outside_hours'],
            ]);

            $posted = $this->postTimesheet($workspace, $user, $day->fresh('events')->loadMissing('workspace'));

            return ['day' => $day->fresh('events'), 'message' => $posted];
        });
    }

    public function presentDay(AttendanceDay $day): array
    {
        $day->loadMissing('workspace');
        $workspace = $day->workspace;
        $standard = $workspace?->standardDayMinutes() ?? (int) config('smackchat.standard_day_minutes', 480);
        $totals = $this->totalsFor($day);
        $counted = $totals['work_minutes'];
        $break = $totals['break_minutes'];
        $portions = $this->calculator->portions($counted, $standard);

        return [
            'id' => $day->id,
            'user_id' => $day->user_id,
            'user_name' => $day->relationLoaded('user') ? $day->user?->name : null,
            'user_email' => $day->relationLoaded('user') ? $day->user?->email : null,
            'work_date' => $day->work_date->toDateString(),
            'check_in_at' => optional($day->check_in_at)?->toIso8601String(),
            'check_out_at' => optional($day->check_out_at)?->toIso8601String(),
            'check_in_latitude' => $day->check_in_latitude,
            'check_in_longitude' => $day->check_in_longitude,
            'check_out_latitude' => $day->check_out_latitude,
            'check_out_longitude' => $day->check_out_longitude,
            'check_in_ip' => $day->check_in_ip,
            'check_out_ip' => $day->check_out_ip,
            'location_verified' => $day->check_in_latitude !== null && $day->check_in_longitude !== null,
            'network_verified' => $day->check_in_ip !== null,
            'presence_verified' => $day->check_in_latitude !== null
                && $day->check_in_longitude !== null
                && $day->check_in_ip !== null,
            'work_minutes' => $counted,
            'break_minutes' => $break,
            'counted_minutes' => $counted,
            'regular_minutes' => $portions['regular_minutes'],
            'overtime_minutes' => $portions['overtime_minutes'],
            'standard_day_minutes' => $standard,
            'work_label' => Duration::label($counted),
            'counted_label' => Duration::label($counted),
            'regular_label' => Duration::label($portions['regular_minutes']),
            'overtime_label' => Duration::label($portions['overtime_minutes']),
            'break_label' => Duration::label($break),
            'status' => $day->status,
            'outside_hours' => $day->outside_hours,
            'non_working_day' => $day->non_working_day,
            'holiday_name' => $day->holiday_name,
            'events' => $day->relationLoaded('events')
                ? $day->events->sortBy('happened_at')->map(fn (AttendanceEvent $event) => [
                    'type' => $event->type,
                    'happened_at' => $event->happened_at->toIso8601String(),
                ])->values()->all()
                : [],
        ];
    }

    /**
     * When restriction is on, require office GPS radius AND office network IP.
     * Either signal alone is too easy to fake from home.
     *
     * @return array{latitude?: float, longitude?: float, ip?: string}|null
     */
    private function requirePresence(Workspace $workspace, ?array $location, ?string $clientIp, string $verb): ?array
    {
        if (! $workspace->location_restricted) {
            $ip = $this->safeIp($clientIp);

            return $ip ? ['ip' => $ip] : null;
        }

        if ($workspace->location_latitude === null || $workspace->location_longitude === null) {
            throw ValidationException::withMessages([
                'location' => 'This workspace has presence check turned on, but no approved place is set yet.',
            ]);
        }

        $allowedIps = OfficeNetwork::normalize($workspace->location_allowed_ips);
        if ($allowedIps === []) {
            throw ValidationException::withMessages([
                'network' => 'This workspace has presence check turned on, but no office network IPs are set yet.',
            ]);
        }

        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;

        if (! is_numeric($latitude) || ! is_numeric($longitude)) {
            throw ValidationException::withMessages([
                'location' => 'Share your location before you '.$verb.'.',
            ]);
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        if ($latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180) {
            throw ValidationException::withMessages(['location' => 'Those coordinates are not valid.']);
        }

        $radius = max(1, (int) ($workspace->location_radius_m ?? 250));
        $distance = $this->distanceInMeters(
            $latitude,
            $longitude,
            (float) $workspace->location_latitude,
            (float) $workspace->location_longitude,
        );

        if ($distance > $radius) {
            throw ValidationException::withMessages([
                'location' => 'You are about '.round($distance).'m away from the approved location. The limit is '.$radius.'m.',
            ]);
        }

        $ip = $this->safeIp($clientIp);
        if (! OfficeNetwork::allows($ip, $allowedIps)) {
            throw ValidationException::withMessages([
                'network' => 'Join the office network before you '.$verb.'. Your connection is not on an approved IP.',
            ]);
        }

        return [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'ip' => $ip,
        ];
    }

    private function safeIp(?string $ip): ?string
    {
        if (! is_string($ip) || $ip === '') {
            return null;
        }

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : null;
    }

    private function distanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earth = 6371000;
        $latFrom = deg2rad($lat1);
        $latTo = deg2rad($lat2);
        $deltaLat = deg2rad($lat2 - $lat1);
        $deltaLon = deg2rad($lon2 - $lon1);
        $a = sin($deltaLat / 2) ** 2 + cos($latFrom) * cos($latTo) * sin($deltaLon / 2) ** 2;

        return 2 * $earth * asin(min(1, sqrt($a)));
    }

    /**
     * @return array{work_minutes: int, break_minutes: int}
     */
    private function totalsFor(AttendanceDay $day): array
    {
        $events = $day->relationLoaded('events') ? $day->events : $day->events()->get();

        if ($events->isEmpty()) {
            return [
                'work_minutes' => (int) ($day->work_minutes ?? 0),
                'break_minutes' => (int) ($day->break_minutes ?? 0),
            ];
        }

        $end = $day->check_out_at ?: now();
        $totals = $this->calculator->summarize($events, $end);

        if (
            $day->status === 'completed'
            && (
                (int) ($day->work_minutes ?? 0) !== $totals['work_minutes']
                || (int) ($day->break_minutes ?? 0) !== $totals['break_minutes']
            )
        ) {
            $day->forceFill([
                'work_minutes' => $totals['work_minutes'],
                'break_minutes' => $totals['break_minutes'],
            ])->saveQuietly();
        }

        return $totals;
    }

    private function event(AttendanceDay $day, string $type, Carbon $at): void
    {
        $day->events()->create([
            'type' => $type,
            'happened_at' => $at,
        ]);
    }

    private function postTimesheet(Workspace $workspace, User $user, AttendanceDay $day): ?array
    {
        $channel = Channel::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', Channel::ATTENDANCE)
            ->first();

        if (! $channel) {
            return null;
        }

        $localIn = $day->check_in_at->copy()->timezone($workspace->timezone);
        $localOut = $day->check_out_at->copy()->timezone($workspace->timezone);
        $standard = $workspace->standardDayMinutes();
        $lines = [
            $user->name.' checked out on '.$day->work_date->format('D j M').'.',
            'In '.$localIn->format('g:i A').' · out '.$localOut->format('g:i A').'.',
            'Worked '.Duration::label($day->work_minutes).' with '.Duration::label($day->break_minutes).' of breaks.',
        ];

        $overtime = $this->calculator->portions((int) $day->work_minutes, $standard)['overtime_minutes'];
        if ($overtime > 0) {
            $lines[] = Duration::label($overtime).' of that is overtime, past a '.Duration::label($standard).' day. Breaks are not counted.';
        }

        if ($day->holiday_name) {
            $lines[] = 'The office clock marks this as a holiday: '.$day->holiday_name.'.';
        } elseif ($day->non_working_day) {
            $lines[] = 'This shift landed on a day outside the working week.';
        } elseif ($day->outside_hours) {
            $lines[] = 'Some of this shift sat outside '.$workspace->clockLabel($workspace->work_start).'–'.$workspace->clockLabel($workspace->work_end).'.';
        }

        $result = $this->messages->post(
            $channel,
            $user,
            RichText::paragraph(implode(' ', $lines)),
            null,
            'attendance',
            true
        );

        return $result['message'];
    }
}
