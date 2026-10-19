<?php

use App\Modules\Nodes\Http\Controllers\Admin\NodeController;
use App\Modules\Tenancy\Http\Middleware\EnsurePlatformAdmin;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', EnsurePlatformAdmin::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('nodes', [NodeController::class, 'index'])->name('nodes.index');
    Route::post('nodes/sync', [NodeController::class, 'sync'])->middleware('throttle:6,1')->name('nodes.sync');
    Route::post('nodes/{node}/check', [NodeController::class, 'check'])->whereNumber('node')->middleware('throttle:12,1')->name('nodes.check');
});
