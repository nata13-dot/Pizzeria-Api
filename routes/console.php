<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('pizzeria:daily-operations')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('pizzeria:daily-operations --report')->dailyAt('23:55')->withoutOverlapping();
Schedule::command('pizzeria:backup')->dailyAt('02:00')->withoutOverlapping();
