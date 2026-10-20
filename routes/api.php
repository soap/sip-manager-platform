<?php

use App\Http\Middleware\Idempotent;
use App\Modules\Provisioning\Http\Controllers\Api\OperationController;
use App\Modules\Telephony\Http\Controllers\Api\ExtensionController;
use App\Modules\Tenancy\Http\Controllers\Api\TenantController;
use App\Modules\Tenancy\Http\Middleware\SetApiTenant;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

/*
| API v1 for tenants (plan v0.2 §8). A token works in the tenant it was issued in; "read" tokens
| may only look, "write" tokens may also change (with the owner or admin role).
*/

Route::prefix('v1')->name('api.v1.')->middleware(['auth:sanctum', SetApiTenant::class, 'throttle:api'])->group(function (): void {
    Route::middleware(CheckForAnyAbility::class.':read,write')->group(function (): void {
        Route::get('tenant', [TenantController::class, 'show'])->name('tenant.show');
        Route::get('extensions', [ExtensionController::class, 'index'])->name('extensions.index');
        Route::get('extensions/{extension}', [ExtensionController::class, 'show'])->whereNumber('extension')->name('extensions.show');
        Route::get('operations/{operation}', [OperationController::class, 'show'])->name('operations.show');
    });

    Route::middleware(CheckAbilities::class.':write')->group(function (): void {
        Route::post('extensions', [ExtensionController::class, 'store'])->middleware(Idempotent::class)->name('extensions.store');
        Route::patch('extensions/{extension}', [ExtensionController::class, 'update'])->whereNumber('extension')->name('extensions.update');
        Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])->whereNumber('extension')->name('extensions.destroy');
        Route::post('extensions/{extension}/rotate-credential', [ExtensionController::class, 'rotateCredential'])
            ->whereNumber('extension')
            ->middleware([Idempotent::class, 'throttle:10,1'])
            ->name('extensions.rotate-credential');
    });
});
