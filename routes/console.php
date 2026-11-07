<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('smackchat:prune-history')->daily();
