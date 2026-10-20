<?php

use App\Modules\Telephony\Http\Controllers\ExtensionController;
use App\Modules\Telephony\Http\Controllers\RingGroupController;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified', SetTenantContext::class])->prefix('t/{tenant:slug}')->name('tenant.')->group(function () {
    Route::get('extensions', [ExtensionController::class, 'index'])->name('extensions.index');
    Route::post('extensions', [ExtensionController::class, 'store'])->name('extensions.store');
    Route::patch('extensions/{extension}', [ExtensionController::class, 'update'])->whereNumber('extension')->name('extensions.update');
    Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])->whereNumber('extension')->name('extensions.destroy');
    Route::post('extensions/{extension}/rotate-credential', [ExtensionController::class, 'rotateCredential'])->whereNumber('extension')->middleware('throttle:10,1')->name('extensions.rotate-credential');
    Route::patch('extensions/{extension}/voicemail', [ExtensionController::class, 'voicemail'])->whereNumber('extension')->name('extensions.voicemail');
    Route::post('extensions/{extension}/voicemail-pin', [ExtensionController::class, 'resetVoicemailPin'])->whereNumber('extension')->middleware('throttle:10,1')->name('extensions.voicemail-pin');
    Route::post('extensions/{extension}/reapply', [ExtensionController::class, 'reapply'])->whereNumber('extension')->middleware('throttle:10,1')->name('extensions.reapply');
    Route::get('ring-groups', [RingGroupController::class, 'index'])->name('ring-groups.index');
    Route::post('ring-groups', [RingGroupController::class, 'store'])->name('ring-groups.store');
    Route::get('ring-groups/{ringGroup}/edit', [RingGroupController::class, 'edit'])->whereNumber('ringGroup')->name('ring-groups.edit');
    Route::patch('ring-groups/{ringGroup}', [RingGroupController::class, 'update'])->whereNumber('ringGroup')->name('ring-groups.update');
    Route::delete('ring-groups/{ringGroup}', [RingGroupController::class, 'destroy'])->whereNumber('ringGroup')->name('ring-groups.destroy');
    Route::post('ring-groups/{ringGroup}/reapply', [RingGroupController::class, 'reapply'])->whereNumber('ringGroup')->middleware('throttle:10,1')->name('ring-groups.reapply');
});
