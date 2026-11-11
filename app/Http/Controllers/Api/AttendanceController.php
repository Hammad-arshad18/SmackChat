<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceDay;
use App\Services\AblyPublisher;
use App\Services\AttendanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class AttendanceController extends ApiController
{
    public function today(Request $request, AttendanceService $attendance)
    {
        $day = $attendance->today($this->workspace($request), $request->user());

        return response()->json([
            'day' => $day ? $attendance->presentDay($day) : null,
        ]);
    }

    public function floor(Request $request, AttendanceService $attendance)
    {
        $workspace = $this->workspace($request);

        if (! $this->membership($request)->managesOffice()) {
            return response()->json(['rows' => []]);
        }

        $date = now()->timezone($workspace->timezone)->toDateString();

        $rows = AttendanceDay::query()
            ->with(['user', 'events', 'workspace'])
            ->where('workspace_id', $workspace->id)
            ->whereDate('work_date', $date)
            ->orderBy('user_id')
            ->get();

        return response()->json([
            'date' => $date,
            'rows' => $rows->map(fn (AttendanceDay $day) => $attendance->presentDay($day))->values(),
        ]);
    }

    public function punch(Request $request, AttendanceService $attendance, AblyPublisher $ably)
    {
        $data = $request->validate([
            'action' => ['required', Rule::in(['check_in', 'break_in', 'break_out', 'check_out'])],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'accuracy' => ['nullable', 'numeric', 'min:0'],
        ]);

        $workspace = $this->workspace($request);
        $result = $attendance->punch(
            $workspace,
            $request->user(),
            $data['action'],
            [
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'accuracy' => $data['accuracy'] ?? null,
            ],
            $request->ip(),
        );
        $day = $attendance->presentDay($result['day']);
        $ably->publishShift($workspace->id, $request->user()->id, $day);

        return response()->json([
            'day' => $day,
            'message' => $result['message'],
        ]);
    }

    public function month(Request $request, AttendanceService $attendance)
    {
        [$start, $end] = $this->range($request);
        $rows = $this->query($request, $start, $end)->get();

        return response()->json([
            'month' => $start->format('Y-m'),
            'rows' => $rows->map(fn (AttendanceDay $day) => $attendance->presentDay($day))->values(),
        ]);
    }

    public function export(Request $request, AttendanceService $attendance)
    {
        $this->requireManager($request);
        [$start, $end] = $this->range($request);
        $workspace = $this->workspace($request);
        $rows = $this->query($request, $start, $end)->get();
        $filename = 'attendance-'.$workspace->slug.'-'.$start->format('Y-m').'.csv';

        return response()->streamDownload(function () use ($rows, $workspace, $attendance) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Employee', 'Email', 'Date', 'Weekday', 'Check in', 'Check out', 'Worked', 'Regular', 'Overtime', 'Breaks', 'Status', 'Notes']);

            foreach ($rows as $day) {
                $presented = $attendance->presentDay($day);
                $notes = collect([
                    $day->holiday_name ? 'Holiday: '.$day->holiday_name : null,
                    $day->non_working_day ? 'Outside the working week' : null,
                    $day->outside_hours ? 'Outside scheduled hours' : null,
                ])->filter()->implode('; ');

                fputcsv($out, [
                    $day->user?->name,
                    $day->user?->email,
                    $day->work_date->toDateString(),
                    $day->work_date->copy()->timezone($workspace->timezone)->format('l'),
                    optional($day->check_in_at)?->copy()->timezone($workspace->timezone)->format('Y-m-d H:i'),
                    optional($day->check_out_at)?->copy()->timezone($workspace->timezone)->format('Y-m-d H:i'),
                    $this->hours($presented['counted_minutes']),
                    $this->hours($presented['regular_minutes']),
                    $this->hours($presented['overtime_minutes']),
                    $this->hours($presented['break_minutes']),
                    $day->status,
                    $notes,
                ]);
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function query(Request $request, Carbon $start, Carbon $end)
    {
        $query = AttendanceDay::query()
            ->with(['user', 'events', 'workspace'])
            ->where('workspace_id', $this->workspace($request)->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->orderBy('work_date')
            ->orderBy('user_id');

        if (! $this->membership($request)->managesOffice()) {
            $query->where('user_id', $request->user()->id);
        }

        return $query;
    }

    private function range(Request $request): array
    {
        $month = $request->validate([
            'month' => ['nullable', 'date_format:Y-m'],
        ])['month'] ?? now()->format('Y-m');

        $start = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return [$start, $start->copy()->endOfMonth()];
    }

    private function hours(int $minutes): string
    {
        return number_format($minutes / 60, 2, '.', '');
    }
}
