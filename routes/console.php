<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| WhatsApp maintenance
| cPanel cron (required):
| * * * * * cd /home/mgplasti/mgplastic_app && php artisan schedule:run >> /dev/null 2>&1
*/
Schedule::command('whatsapp:health')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('whatsapp:prune-logs --days=60')->dailyAt('03:15')->withoutOverlapping();
Schedule::command('whatsapp:prune-cards --days=7')->dailyAt('03:30')->withoutOverlapping();
