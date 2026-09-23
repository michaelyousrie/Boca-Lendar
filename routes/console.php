<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('appointments:sync')->everyMinute()->withoutOverlapping();
