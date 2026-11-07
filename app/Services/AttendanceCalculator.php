<?php

namespace App\Services;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceCalculator
{
    /**
     * @param  Collection<int, object{type: string, happened_at: CarbonInterface|string}>  $events
     * @return array{work_minutes: int, break_minutes: int}
     */
    public function summarize(Collection $events, CarbonInterface $checkOut): array
    {
        $work = 0;
        $break = 0;
        $openWork = null;
        $openBreak = null;

        foreach ($events->sortBy(fn ($event) => Carbon::parse($event->happened_at)->getTimestamp()) as $event) {
            $at = Carbon::parse($event->happened_at);

            if ($event->type === 'check_in') {
                $openWork = $at;
                $openBreak = null;
            } elseif ($event->type === 'break_in' && $openWork) {
                $work += $this->minutesBetween($openWork, $at);
                $openWork = null;
                $openBreak = $at;
            } elseif ($event->type === 'break_out' && $openBreak) {
                $break += $this->minutesBetween($openBreak, $at);
                $openBreak = null;
                $openWork = $at;
            }
        }

        $end = Carbon::parse($checkOut);

        if ($openBreak) {
            $break += $this->minutesBetween($openBreak, $end);
        } elseif ($openWork) {
            $work += $this->minutesBetween($openWork, $end);
        }

        return [
            'work_minutes' => $work,
            'break_minutes' => $break,
        ];
    }

    private function minutesBetween(CarbonInterface $start, CarbonInterface $end): int
    {
        if ($end->lessThan($start)) {
            return 0;
        }

        return (int) round($start->diffInMinutes($end));
    }
}
