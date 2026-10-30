<?php

use App\Models\IdempotencyKey;
use App\Modules\CallRecords\Models\CallRecord;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
| The overlap locks expire after a few minutes instead of the default day, so a scheduler killed
| mid-run (e.g. stopping `composer run dev`) does not leave a lock that skips the task until tomorrow.
*/

Schedule::command('nodes:sync')->everyFiveMinutes()->withoutOverlapping(10);
Schedule::command('provisioning:relay')->everyTwoSeconds()->withoutOverlapping(1);
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class, CallRecord::class]])->daily();
Schedule::command('authorization-codes:expire')->everyMinute()->withoutOverlapping(1);
Schedule::command('nodes:reconcile')->everyFifteenMinutes()->withoutOverlapping(30);
Schedule::command('cdr:sync')->everyMinute()->withoutOverlapping(5);
