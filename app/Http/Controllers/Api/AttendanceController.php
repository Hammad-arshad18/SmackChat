<?php

namespace App\Http\Controllers\Api;

use App\Models\AttendanceDay;
use App\Services\AblyPublisher;
use App\Services\AttendanceService;
use App\Services\AttendanceWorkbook;
use App\Services\AuditLogger;
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

    public function export(Request $request, AttendanceWorkbook $workbook, AuditLogger $audit)
    {
        $this->requireManager($request);
        [$start, $end] = $this->range($request);
        $workspace = $this->workspace($request);
        $rows = $this->query($request, $start, $end)->get();
        $filename = 'attendance-'.$workspace->slug.'-'.$start->format('Y-m').'.xlsx';
        $bytes = $workbook->render($workspace, $start, $end, $rows);

        $audit->record('attendance.exported', $workspace, $request->user(), [
            'month' => $start->format('Y-m'),
            'rows' => $rows->count(),
        ], null, $request);

        return response()->streamDownload(function () use ($bytes) {
            echo $bytes;
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
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
}
