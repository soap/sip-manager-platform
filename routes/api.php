<?php

use Illuminate\Support\Facades\Route;

/*
| API v1 for tenants (extensions, devices, operations, call records) comes with milestone M5.
*/

Route::prefix('v1')->name('api.v1.')->middleware('auth:sanctum')->group(function (): void {
    //
});
