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
}
