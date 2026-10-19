<?php

use App\Modules\Tenancy\Http\Controllers\Admin\TenantController;
use App\Modules\Tenancy\Http\Controllers\AuditLogController;
use App\Modules\Tenancy\Http\Controllers\MemberController;
use App\Modules\Tenancy\Http\Controllers\TenantDashboardController;
use App\Modules\Tenancy\Http\Middleware\EnsurePlatformAdmin;
use App\Modules\Tenancy\Http\Middleware\SetTenantContext;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::middleware(EnsurePlatformAdmin::class)->prefix('admin')->name('admin.')->group(function () {
        Route::get('tenants', [TenantController::class, 'index'])->name('tenants.index');
        Route::post('tenants', [TenantController::class, 'store'])->name('tenants.store');
    });

    Route::middleware(SetTenantContext::class)->prefix('t/{tenant:slug}')->name('tenant.')->group(function () {
        Route::get('/', [TenantDashboardController::class, 'show'])->name('dashboard');

        Route::get('members', [MemberController::class, 'index'])->name('members.index');
        Route::post('members', [MemberController::class, 'store'])->name('members.store');
        Route::patch('members/{membership}', [MemberController::class, 'update'])->whereNumber('membership')->name('members.update');
        Route::delete('members/{membership}', [MemberController::class, 'destroy'])->whereNumber('membership')->name('members.destroy');

        Route::get('audit-log', [AuditLogController::class, 'index'])->name('audit-log.index');
    });
});
