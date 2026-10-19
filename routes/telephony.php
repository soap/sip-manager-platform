<?php

use App\Modules\Telephony\Http\Controllers\ExtensionController;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetTenantContext::class])->prefix('t/{tenant:slug}')->name('tenant.')->group(function () {
    Route::get('extensions', [ExtensionController::class, 'index'])->name('extensions.index');
    Route::post('extensions', [ExtensionController::class, 'store'])->name('extensions.store');
    Route::patch('extensions/{extension}', [ExtensionController::class, 'update'])->whereNumber('extension')->name('extensions.update');
    Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])->whereNumber('extension')->name('extensions.destroy');
});
