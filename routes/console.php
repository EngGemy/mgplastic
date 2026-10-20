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

/*
| Reputation / warranty / reminders (Africa/Tripoli)
| Disable any job with REPUTATION_ENABLED / WARRANTY_ENABLED / REMINDERS_ENABLED env flags
| (commands no-op or services short-circuit when disabled).
*/
$tz = (string) config('reminders.timezone', 'Africa/Tripoli');

Schedule::command('reputation:recompute')
    ->dailyAt('02:00')
    ->timezone($tz)
    ->withoutOverlapping()
    ->when(fn () => (bool) config('reputation.enabled', true));

Schedule::command('streak:process-weekly')
    ->weeklyOn(0, '00:10') // Sunday
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('streak:notify-at-risk')
    ->weeklyOn(3, '17:00') // Wednesday
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('reminders:dispatch weekly')
    ->weeklyOn(4, '18:00') // Thursday
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('reminders:dispatch daily')
    ->dailyAt('20:00')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('ai:prune-message-cache')
    ->dailyAt('03:45')
    ->timezone($tz)
    ->withoutOverlapping();

Schedule::command('testimonials:generate')
    ->dailyAt('09:15')
    ->timezone($tz)
    ->withoutOverlapping()
    ->when(fn () => (bool) config('testimonials.enabled', true)
        && (bool) config('testimonials.daily_generate', true));
