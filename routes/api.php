<?php

use App\Http\Middleware\Idempotent;
use App\Modules\Provisioning\Http\Controllers\Api\OperationController;
use App\Modules\Telephony\Http\Controllers\Api\AuthorizationCodeController;
use App\Modules\Telephony\Http\Controllers\Api\CallingProfileController;
use App\Modules\Telephony\Http\Controllers\Api\CrossTenantController;
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
        Route::get('calling-profiles', [CallingProfileController::class, 'index'])->name('calling-profiles.index');
        Route::get('calling-profiles/{profile}', [CallingProfileController::class, 'show'])->whereNumber('profile')->name('calling-profiles.show');
        Route::get('authorization-codes', [AuthorizationCodeController::class, 'index'])->name('authorization-codes.index');
        Route::get('authorization-codes/{code}', [AuthorizationCodeController::class, 'show'])->whereNumber('code')->name('authorization-codes.show');
        Route::get('cross-tenant', [CrossTenantController::class, 'show'])->name('cross-tenant.show');
        Route::get('directory', [CrossTenantController::class, 'directory'])->name('directory');
    });

    Route::middleware(CheckAbilities::class.':write')->group(function (): void {
        Route::post('extensions', [ExtensionController::class, 'store'])->middleware(Idempotent::class)->name('extensions.store');
        Route::patch('extensions/{extension}', [ExtensionController::class, 'update'])->whereNumber('extension')->name('extensions.update');
        Route::delete('extensions/{extension}', [ExtensionController::class, 'destroy'])->whereNumber('extension')->name('extensions.destroy');
        Route::post('extensions/{extension}/rotate-credential', [ExtensionController::class, 'rotateCredential'])
            ->whereNumber('extension')
            ->middleware([Idempotent::class, 'throttle:10,1'])
            ->name('extensions.rotate-credential');

        Route::post('calling-profiles', [CallingProfileController::class, 'store'])->middleware(Idempotent::class)->name('calling-profiles.store');
        Route::patch('calling-profiles/{profile}', [CallingProfileController::class, 'update'])->whereNumber('profile')->name('calling-profiles.update');
        Route::post('calling-profiles/{profile}/default', [CallingProfileController::class, 'makeDefault'])->whereNumber('profile')->name('calling-profiles.default');
        Route::delete('calling-profiles/{profile}', [CallingProfileController::class, 'destroy'])->whereNumber('profile')->name('calling-profiles.destroy');

        Route::post('authorization-codes', [AuthorizationCodeController::class, 'store'])->middleware([Idempotent::class, 'throttle:20,1'])->name('authorization-codes.store');
        Route::patch('authorization-codes/{code}', [AuthorizationCodeController::class, 'update'])->whereNumber('code')->name('authorization-codes.update');
        Route::post('authorization-codes/{code}/reset', [AuthorizationCodeController::class, 'reset'])
            ->whereNumber('code')
            ->middleware([Idempotent::class, 'throttle:10,1'])
            ->name('authorization-codes.reset');
        Route::delete('authorization-codes/{code}', [AuthorizationCodeController::class, 'destroy'])->whereNumber('code')->name('authorization-codes.destroy');

        Route::patch('cross-tenant', [CrossTenantController::class, 'update'])->name('cross-tenant.update');
    });
});
