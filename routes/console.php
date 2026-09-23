<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('appointments:sync')->everyMinute()->withoutOverlapping();

Schedule::command('calendar:sync')->everyMinute()->withoutOverlapping();
