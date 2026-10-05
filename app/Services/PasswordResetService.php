<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetService
{
    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => $email]);
    }

    /**
     * @param  array{email: string, token: string, password: string}  $credentials
     */
    public function reset(array $credentials): void
    {
        $status = Password::reset($credentials, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password)])->save();

            $user->tokens()->delete();

            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages([
                'email' => ['This password reset token is invalid or has expired.'],
            ]);
        }
    }
}
