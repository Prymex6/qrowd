<?php

use App\Models\SearchCache;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Zadania cykliczne
|--------------------------------------------------------------------------
*/

/**
 * Widening the catalogue daily with the artists it still lacks.
 *
 * The YouTube quota resets at midnight Pacific time, which is 9 a.m. here. We
 * start a quarter of an hour later, to be sure Google's counter has already gone
 * back to zero.
 *
 * The command watches its own budget and leaves a reserve for the guests'
 * searches, so it can run unattended until it exhausts the queue.
 */
Schedule::command('catalog:find-artists --reserve=2500')
    ->dailyAt('09:15')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/wykonawcy.log'));

/**
 * Clearing expired entries from the search cache - otherwise the table grows
 * without end, and the old results are out of date anyway.
 */
Schedule::call(function () {
    SearchCache::where('expires_at', '<', now())->delete();
})->weekly()->name('czyszczenie-cache');

/**
 * Deleting photos once the retention period has passed.
 *
 * We promise the host in the settings that after a set time the photos will be
 * gone. Those are particular people's likenesses, so the promise has to be kept
 * by code rather than by somebody's memory. In the morning, so that any failure
 * shows during the day instead of being discovered a week later.
 */
Schedule::command('photos:cleanup')
    ->dailyAt('04:30')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/zdjecia.log'));
