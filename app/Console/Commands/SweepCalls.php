<?php

namespace App\Console\Commands;

use App\Services\CallService;
use Illuminate\Console\Command;

class SweepCalls extends Command
{
    protected $signature = 'smackchat:sweep-calls';

    protected $description = 'End huddles nobody answered or everyone left, and take their ring off closed phones';

    public function handle(CallService $calls): int
    {
        $ended = $calls->sweep();

        if ($ended > 0) {
            $this->info("Ended {$ended} call(s).");
        }

        return self::SUCCESS;
    }
}
