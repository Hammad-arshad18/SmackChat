<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Shifts punched while AttendanceService mutated its clock stored the office
     * wall time as UTC, so the check-in landed hours in the future and the live
     * counter sat at 00:00:00. Move those rows back by the workspace offset.
     *
     * Only rows whose check-in is still in the future are touched, and only when
     * the gap from check-in to created_at matches the office offset. Anything
     * else (normal rows, clock skew, seeded data) is left alone.
     */
    public function up(): void
    {
        $appTimezone = (string) config('app.timezone');
        $timezones = DB::table('workspaces')->pluck('timezone', 'id');

        DB::table('attendance_days')
            ->select(['id', 'workspace_id', 'check_in_at', 'check_out_at', 'created_at'])
            ->whereNotNull('check_in_at')
            ->where('check_in_at', '>', now()->addHour())
            ->orderBy('id')
            ->chunkById(100, function ($days) use ($appTimezone, $timezones) {
                foreach ($days as $day) {
                    if (! $day->created_at) {
                        continue;
                    }

                    $timezone = $timezones[$day->workspace_id] ?? $appTimezone;
                    $checkIn = Carbon::parse($day->check_in_at, $appTimezone);
                    $created = Carbon::parse($day->created_at, $appTimezone);
                    $shift = $checkIn->copy()->timezone($timezone)->getOffset();

                    if ($shift === 0 || abs($checkIn->getTimestamp() - $created->getTimestamp() - $shift) > 900) {
                        continue;
                    }

                    DB::transaction(function () use ($day, $appTimezone, $shift) {
                        DB::table('attendance_days')->where('id', $day->id)->update([
                            'check_in_at' => Carbon::parse($day->check_in_at, $appTimezone)->subSeconds($shift),
                            'check_out_at' => $day->check_out_at
                                ? Carbon::parse($day->check_out_at, $appTimezone)->subSeconds($shift)
                                : null,
                        ]);

                        $events = DB::table('attendance_events')
                            ->where('attendance_day_id', $day->id)
                            ->orderBy('id')
                            ->get(['id', 'happened_at']);

                        foreach ($events as $event) {
                            DB::table('attendance_events')->where('id', $event->id)->update([
                                'happened_at' => Carbon::parse($event->happened_at, $appTimezone)->subSeconds($shift),
                            ]);
                        }
                    });
                }
            });
    }

    public function down(): void
    {
        // Timestamps are repaired in place; the original values are not recoverable.
    }
};
