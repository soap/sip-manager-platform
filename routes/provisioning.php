<?php

use App\Modules\Provisioning\Http\Controllers\Admin\OperationController as AdminOperationController;
use App\Modules\Provisioning\Http\Controllers\OperationController;
use App\Modules\Tenancy\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(EnsurePlatformAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::get('operations', [AdminOperationController::class, 'index'])->name('operations.index');
        Route::post('operations/{operation}/retry', [AdminOperationController::class, 'retry'])->middleware('throttle:10,1')->name('operations.retry');
    });

    Route::middleware(SetTenantContext::class)->prefix('t/{tenant:slug}')->name('tenant.')->group(function () {
        Route::get('operations', [OperationController::class, 'index'])->name('operations.index');
        Route::post('operations/{operation}/retry', [OperationController::class, 'retry'])->middleware('throttle:10,1')->name('operations.retry');
    });
});
