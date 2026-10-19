<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

it('creates a verified platform admin', function () {
    $this->artisan('app:create-platform-admin', ['email' => 'Admin@Example.com', 'name' => 'Admin', '--password' => 'a-long-password'])
        ->assertSuccessful();

    $admin = User::query()->where('email', 'admin@example.com')->sole();

    expect($admin->is_platform_admin)->toBeTrue()
        ->and($admin->hasVerifiedEmail())->toBeTrue()
        ->and(Hash::check('a-long-password', $admin->password))->toBeTrue();
});

it('promotes an existing user without touching the password', function () {
    $user = User::factory()->create();
    $password = $user->password;

    $this->artisan('app:create-platform-admin', ['email' => $user->email])->assertSuccessful();

    expect($user->fresh())->is_platform_admin->toBeTrue()->password->toBe($password);
});

it('refuses an invalid email address', function () {
    $this->artisan('app:create-platform-admin', ['email' => 'nope'])->assertFailed();

    expect(User::count())->toBe(0);
});
