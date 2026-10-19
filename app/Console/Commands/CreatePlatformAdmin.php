<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;

/**
 * Registration is closed, so the first platform admin is created here. An existing user is
 * promoted instead.
 */
#[Signature('app:create-platform-admin {email} {name?} {--password= : Password for a new user; asked for when omitted}')]
#[Description('Create a platform admin, or make an existing user one')]
class CreatePlatformAdmin extends Command
{
    public function handle(): int
    {
        $emailArgument = $this->argument('email');
        $email = is_string($emailArgument) ? Str::lower($emailArgument) : '';

        if (Validator::make(['email' => $email], ['email' => ['required', 'email']])->fails()) {
            $this->error('That is not a valid email address.');

            return self::FAILURE;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $passwordOption = $this->option('password');
            $password = is_string($passwordOption)
                ? $passwordOption
                : password('Password', required: true, validate: fn (string $value): ?string => strlen($value) < 12 ? 'Use at least 12 characters.' : null);
            $nameArgument = $this->argument('name');

            $user = User::query()->create([
                'name' => is_string($nameArgument) ? $nameArgument : Str::before($email, '@'),
                'email' => $email,
                'password' => $password,
            ]);
            $user->markEmailAsVerified();
        }

        $user->forceFill(['is_platform_admin' => true])->save();

        $this->info("{$user->email} is a platform admin. Turn on two-factor authentication in Settings → Security before opening the platform pages.");

        return self::SUCCESS;
    }
}
