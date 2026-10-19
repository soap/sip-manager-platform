<?php

namespace App\Modules\Tenancy\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Finds the user with the email address, or creates one with a random password and emails a
 * password reset link as the invitation. There is no public registration.
 */
class InviteUser
{
    public function handle(string $name, string $email): User
    {
        $email = Str::lower(trim($email));
        $user = User::query()->where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => Str::password(32),
        ]);

        Password::broker()->sendResetLink(['email' => $email]);

        return $user;
    }
}
