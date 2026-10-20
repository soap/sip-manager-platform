<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Signs in a new user, or the given one, and returns it.
 */
function signIn(?User $user = null): User
{
    $user ??= User::factory()->create();

    test()->actingAs($user);

    return $user;
}

require_once __DIR__.'/Support/provisioning.php';
