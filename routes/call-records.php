<?php

use App\Modules\CallRecords\Http\Controllers\CallRecordController;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetTenantContext::class])->prefix('t/{tenant:slug}')->name('tenant.')->group(function () {
    Route::get('calls', [CallRecordController::class, 'index'])->name('calls.index');
});
