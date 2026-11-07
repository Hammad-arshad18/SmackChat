<?php

namespace Database\Seeders;

use App\Models\Holiday;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\ChannelAccess;
use App\Services\MessageWriter;
use App\Services\WorkspaceProvisioner;
use App\Support\RichText;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $provisioner = app(WorkspaceProvisioner::class);
        $messages = app(MessageWriter::class);
        $attendance = app(AttendanceService::class);
        $access = app(ChannelAccess::class);

        $ada = $this->person('Ada Lovelace', 'ada@northwind.test', 'Founder', 'Asia/Dubai');
        $grace = $this->person('Grace Hopper', 'grace@northwind.test', 'Engineering', 'Asia/Dubai');
        $lin = $this->person('Lin Zhao', 'lin@northwind.test', 'Design', 'Asia/Dubai');

        $workspace = $provisioner->create($ada, 'Northwind Studio', 'Asia/Dubai');
        $provisioner->admit($workspace, $grace, 'member');
        $provisioner->admit($workspace, $lin, 'admin');

        $workspace->holidays()->create([
            'name' => 'New Year',
            'observed_on' => '2026-01-01',
            'repeats_yearly' => true,
        ]);
        Holiday::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Studio retreat',
            'observed_on' => '2026-12-18',
            'repeats_yearly' => false,
        ]);

        $design = $workspace->channels()->create([
            'name' => 'design',
            'slug' => 'design',
            'type' => 'public',
            'description' => 'Work in progress, critiques, and launch visuals.',
            'topic' => 'Share frames before they go to a client.',
            'created_by' => $lin->id,
        ]);
        foreach ([$ada, $grace, $lin] as $person) {
            $access->addMember($design, $person);
        }

        $general = $workspace->channels()->where('slug', 'general')->first();
        $intro = $messages->post($general, $ada, RichText::paragraph('Floor clock is live. Working days are Monday to Friday, 9:00 to 17:00, Asia/Dubai. Checkout posts the day into Timesheets.'));
        $messages->post(
            $general,
            $grace,
            RichText::paragraph('I will keep the compiler thread in here and the longer debugging notes in a thread.'),
            $intro['message']['id']
        );
        $messages->post($design, $lin, RichText::paragraph('The coral mark on the navy rail is the one I want on the loading screen. @Ada Lovelace can you look before Friday?'));

        $key = collect([$ada->id, $grace->id])->sort()->implode(':');
        $dm = $workspace->channels()->create([
            'name' => 'dm',
            'slug' => 'dm-ada-grace',
            'type' => 'dm',
            'dm_key' => $key,
            'created_by' => $ada->id,
        ]);
        $access->addMember($dm, $ada);
        $access->addMember($dm, $grace);
        $messages->post($dm, $ada, RichText::paragraph('This stays between us. The archive plan is $7 a person if we want history past 90 days.'));

        try {
            Carbon::setTestNow(Carbon::parse('2026-11-06 09:00:00', 'Asia/Dubai'));
            $attendance->punch($workspace, $grace, 'check_in');
            Carbon::setTestNow(Carbon::parse('2026-11-06 12:00:00', 'Asia/Dubai'));
            $attendance->punch($workspace, $grace, 'break_in');
            Carbon::setTestNow(Carbon::parse('2026-11-06 12:30:00', 'Asia/Dubai'));
            $attendance->punch($workspace, $grace, 'break_out');
            Carbon::setTestNow(Carbon::parse('2026-11-06 17:15:00', 'Asia/Dubai'));
            $attendance->punch($workspace, $grace, 'check_out');

            Carbon::setTestNow(Carbon::parse('2026-11-07 09:05:00', 'Asia/Dubai'));
            $attendance->punch($workspace, $ada, 'check_in');
        } finally {
            Carbon::setTestNow();
        }
    }

    private function person(string $name, string $email, string $title, string $timezone): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => 'password',
            'title' => $title,
            'timezone' => $timezone,
            'avatar_color' => User::colorFor($email),
            'email_verified_at' => now(),
        ]);
    }
}
