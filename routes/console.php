<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('abandoned-carts:update-expired')->everyFiveMinutes();

Schedule::command('whatsapp:process-recovery')->everyFiveMinutes();

Schedule::command('email:process-recovery')->everyFiveMinutes();

Schedule::command('domains:sync-cloudflare')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('domains:sync-cloudflare --include-active')
    ->hourly()
    ->withoutOverlapping();
