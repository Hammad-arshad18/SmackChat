<?php

namespace App\Services;

use App\Models\AttendanceDay;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class AttendanceWorkbook
{
    public function __construct(
        private ScheduleInspector $schedule,
        private AttendanceService $attendance,
    ) {}

    /**
     * @param  Collection<int, AttendanceDay>  $days
     */
    public function render(Workspace $workspace, Carbon $start, Carbon $end, Collection $days): string
    {
        $lines = $days->map(function (AttendanceDay $day) {
            return [
                'day' => $day,
                'presented' => $this->attendance->presentDay($day),
            ];
        });

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getProperties()
            ->setTitle('Attendance '.$start->format('F Y'))
            ->setSubject($workspace->name);

        $this->ledger($spreadsheet->getActiveSheet(), $workspace, $lines);
        $this->summary($spreadsheet->createSheet(), $workspace, $start, $end, $lines);
        $spreadsheet->setActiveSheetIndex(0);

        $path = tempnam(sys_get_temp_dir(), 'attendance');

        try {
            (new Xlsx($spreadsheet))->save($path);

            return (string) file_get_contents($path);
        } finally {
            $spreadsheet->disconnectWorksheets();
            if (is_string($path) && is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @param  Collection<int, array{day: AttendanceDay, presented: array<string, mixed>}>  $lines
     */
    private function ledger(Worksheet $sheet, Workspace $workspace, Collection $lines): void
    {
        $sheet->setTitle('Attendance');
        $this->write($sheet, 1, [
            'Employee',
            'Email',
            'Date',
            'Weekday',
            'Check in',
            'Check out',
            'Worked',
            'Regular',
            'Overtime',
            'Breaks',
            'Status',
            'Notes',
        ]);

        $row = 2;
        foreach ($lines as $line) {
            /** @var AttendanceDay $day */
            $day = $line['day'];
            $presented = $line['presented'];

            $this->write($sheet, $row, [
                $day->user?->name,
                $day->user?->email,
                $day->work_date->toDateString(),
                $day->work_date->format('l'),
                $this->localClock($day->check_in_at, $workspace->timezone),
                $this->localClock($day->check_out_at, $workspace->timezone),
                $this->hours((int) $presented['counted_minutes']),
                $this->hours((int) $presented['regular_minutes']),
                $this->hours((int) $presented['overtime_minutes']),
                $this->hours((int) $presented['break_minutes']),
                $day->status,
                $this->notes($day),
            ]);
            $row++;
        }

        $last = max(1, $row - 1);
        $sheet->getStyle('A1:L1')->getFont()->setBold(true);
        $sheet->getStyle('G2:J'.$last)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->freezePane('A2');
        if ($last > 1) {
            $sheet->setAutoFilter('A1:L'.$last);
        }
        foreach (['A' => 24, 'B' => 28, 'C' => 14, 'D' => 12, 'E' => 18, 'F' => 18, 'L' => 32] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
    }

    /**
     * @param  Collection<int, array{day: AttendanceDay, presented: array<string, mixed>}>  $lines
     */
    private function summary(Worksheet $sheet, Workspace $workspace, Carbon $start, Carbon $end, Collection $lines): void
    {
        $sheet->setTitle('Summary');
        $workingDays = $this->schedule->workingDays($workspace, $start, $end);
        $standardHours = $this->hours($workspace->standardDayMinutes());
        $expectedHours = $this->hours($workingDays * $workspace->standardDayMinutes());
        $people = $this->people($workspace, $lines);

        $this->write($sheet, 1, ['Month', $start->format('F Y')]);
        $this->write($sheet, 2, ['Office hours', $workspace->clockLabel((string) $workspace->work_start).'–'.$workspace->clockLabel((string) $workspace->work_end)]);
        $this->write($sheet, 3, ['Standard day (hours)', $standardHours]);
        $this->write($sheet, 4, ['Working days', $workingDays]);
        $this->write($sheet, 5, ['Expected hours each', $expectedHours]);
        $this->write($sheet, 6, ['Expected hours are the office schedule for this month. Holidays and days off the working week are left out. Difference is worked hours minus expected hours.']);

        $header = 8;
        $this->write($sheet, $header, ['Employee', 'Email', 'Expected hours', 'Worked hours', 'Difference']);

        $row = $header + 1;
        $workedTotal = 0;
        foreach ($people as $person) {
            $worked = $this->hours($person['worked']);
            $workedTotal += $person['worked'];
            $this->write($sheet, $row, [
                $person['name'],
                $person['email'],
                $expectedHours,
                $worked,
                round($worked - $expectedHours, 2),
            ]);
            $row++;
        }

        $count = count($people);
        $this->write($sheet, $row, [
            'Total',
            '',
            round($expectedHours * $count, 2),
            $this->hours($workedTotal),
            round($this->hours($workedTotal) - ($expectedHours * $count), 2),
        ]);

        $sheet->getStyle('A1:A6')->getFont()->setBold(true);
        $sheet->getStyle('A'.$header.':E'.$header)->getFont()->setBold(true);
        $sheet->getStyle('A'.$row.':E'.$row)->getFont()->setBold(true);
        $sheet->getStyle('B3')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('B5')->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('C'.$header.':E'.$row)->getNumberFormat()->setFormatCode(NumberFormat::FORMAT_NUMBER_00);
        $sheet->getStyle('A6')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(6)->setRowHeight(32);
        foreach (['A' => 28, 'B' => 32, 'C' => 18, 'D' => 16, 'E' => 14] as $column => $width) {
            $sheet->getColumnDimension($column)->setWidth($width);
        }
        $sheet->mergeCells('A6:E6');
    }

    /**
     * @param  Collection<int, array{day: AttendanceDay, presented: array<string, mixed>}>  $lines
     * @return list<array{name: string, email: string, worked: int}>
     */
    private function people(Workspace $workspace, Collection $lines): array
    {
        $people = [];

        foreach ($workspace->members()->orderBy('users.name')->get() as $user) {
            $people[$user->id] = $this->person($user, 0);
        }

        foreach ($lines as $line) {
            $day = $line['day'];
            $id = (int) $day->user_id;
            if (! isset($people[$id])) {
                $people[$id] = [
                    'name' => $day->user?->name ?: 'Former teammate',
                    'email' => $day->user?->email ?: '',
                    'worked' => 0,
                ];
            }
            $people[$id]['worked'] += (int) $line['presented']['counted_minutes'];
        }

        $rows = array_values($people);
        usort($rows, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

        return $rows;
    }

    /**
     * @return array{name: string, email: string, worked: int}
     */
    private function person(User $user, int $worked): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'worked' => $worked,
        ];
    }

    private function notes(AttendanceDay $day): string
    {
        return collect([
            $day->holiday_name ? 'Holiday: '.$day->holiday_name : null,
            $day->non_working_day ? 'Outside the working week' : null,
            $day->outside_hours ? 'Outside scheduled hours' : null,
        ])->filter()->implode('; ');
    }

    private function localClock(?Carbon $value, string $timezone): ?string
    {
        if ($value === null) {
            return null;
        }

        return $value->copy()->timezone($timezone)->format('Y-m-d H:i');
    }

    private function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }

    /**
     * @param  list<mixed>  $values
     */
    private function write(Worksheet $sheet, int $row, array $values): void
    {
        foreach (array_values($values) as $index => $value) {
            $coordinate = [$index + 1, $row];
            if (is_int($value) || is_float($value) || $value === null) {
                $sheet->setCellValue($coordinate, $value);

                continue;
            }

            $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_STRING);
        }
    }
}
