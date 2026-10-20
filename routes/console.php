<?php

use App\Models\IdempotencyKey;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('nodes:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('provisioning:relay')->everyTwoSeconds()->withoutOverlapping();
Schedule::command('model:prune', ['--model' => [IdempotencyKey::class]])->daily();
