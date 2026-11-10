<?php

namespace Tests\Unit;

use App\Services\AttendanceCalculator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AttendanceCalculatorTest extends TestCase
{
    public function test_it_subtracts_breaks_from_the_shift(): void
    {
        $events = new Collection([
            (object) ['type' => 'check_in', 'happened_at' => Carbon::parse('2026-11-06 09:00:00')],
            (object) ['type' => 'break_in', 'happened_at' => Carbon::parse('2026-11-06 12:00:00')],
            (object) ['type' => 'break_out', 'happened_at' => Carbon::parse('2026-11-06 12:30:00')],
        ]);

        $totals = (new AttendanceCalculator)->summarize($events, Carbon::parse('2026-11-06 17:15:00'));

        $this->assertSame(465, $totals['work_minutes']);
        $this->assertSame(30, $totals['break_minutes']);
    }

    public function test_it_matches_the_timeline_for_a_full_day_with_lunch(): void
    {
        $events = new Collection([
            (object) ['type' => 'check_in', 'happened_at' => Carbon::parse('2026-11-09 09:00:00')],
            (object) ['type' => 'break_in', 'happened_at' => Carbon::parse('2026-11-09 13:07:00')],
            (object) ['type' => 'break_out', 'happened_at' => Carbon::parse('2026-11-09 14:05:00')],
            (object) ['type' => 'check_out', 'happened_at' => Carbon::parse('2026-11-09 18:05:00')],
        ]);

        $totals = (new AttendanceCalculator)->summarize($events, Carbon::parse('2026-11-09 18:05:00'));

        $this->assertSame(487, $totals['work_minutes']);
        $this->assertSame(58, $totals['break_minutes']);
    }

    public function test_work_past_eight_hours_is_overtime_and_breaks_are_not(): void
    {
        $calculator = new AttendanceCalculator;

        $this->assertSame(
            ['regular_minutes' => 480, 'overtime_minutes' => 0],
            $calculator->portions(480),
        );
        $this->assertSame(
            ['regular_minutes' => 480, 'overtime_minutes' => 45],
            $calculator->portions(525),
        );
        $this->assertSame(
            ['regular_minutes' => 480, 'overtime_minutes' => 7],
            $calculator->portions(487, 480),
        );
    }
}
