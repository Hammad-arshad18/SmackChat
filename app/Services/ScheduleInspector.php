<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class ScheduleInspector
{
    public function describe(Workspace $workspace, CarbonInterface $moment): array
    {
        $local = Carbon::parse($moment)->timezone($workspace->timezone);
        $days = collect($workspace->work_days ?? [])->map(fn ($day) => (int) $day)->all();
        $holiday = $this->holidayOn($workspace, $local);
        $start = $this->clock($workspace->work_start);
        $end = $this->clock($workspace->work_end);
        $startAt = Carbon::parse($local->toDateString().' '.$start, $workspace->timezone);
        $endAt = Carbon::parse($local->toDateString().' '.$end, $workspace->timezone);
        $nonWorking = ! in_array($local->dayOfWeekIso, $days, true);
        $outside = $local->lt($startAt) || $local->gte($endAt);

        return [
            'date' => $local->toDateString(),
            'weekday' => $local->format('l'),
            'iso_day' => $local->dayOfWeekIso,
            'non_working_day' => $nonWorking,
            'holiday' => $holiday?->name,
            'outside_hours' => $outside && ! $nonWorking && $holiday === null,
            'work_start' => substr($start, 0, 5),
            'work_end' => substr($end, 0, 5),
            'timezone' => $workspace->timezone,
            'office_open' => ! $nonWorking && $holiday === null,
        ];
    }

    public function holidayOn(Workspace $workspace, CarbonInterface $local): ?Holiday
    {
        return $workspace->holidays()->get()->first(function (Holiday $holiday) use ($local) {
            $observed = $holiday->observed_on->copy();

            if ($holiday->repeats_yearly) {
                return $observed->month === $local->month && $observed->day === $local->day;
            }

            return $observed->toDateString() === $local->toDateString();
        });
    }

    public function clock(string $value): string
    {
        $value = substr($value, 0, 8);

        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
