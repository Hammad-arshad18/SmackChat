<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('smackchat:prune-history')->daily();
Schedule::command('smackchat:backup')->dailyAt('02:15');
