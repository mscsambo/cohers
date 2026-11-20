<?php

use App\Jobs\CheckOverdueAssignmentsJob;
use Illuminate\Support\Facades\Schedule;

// Due-time checks and fallback routing (section 12). Server timestamps (UTC).
Schedule::job(new CheckOverdueAssignmentsJob)->everyMinute()->withoutOverlapping();

Schedule::command('queue:prune-failed --hours=720')->daily();
