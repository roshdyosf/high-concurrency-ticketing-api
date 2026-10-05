<?php

namespace App\Services;

use App\Exceptions\EmailAlreadyVerifiedException;
use App\Exceptions\InvalidVerificationLinkException;
use App\Models\User;
use Illuminate\Auth\Events\Verified;

class EmailVerificationService
{
    public function sendNotification(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new EmailAlreadyVerifiedException;
        }

        $user->sendEmailVerificationNotification();
    }

    public function verify(int $id, string $hash): void
    {
        $user = User::findOrFail($id);

        if (! hash_equals(sha1($user->getEmailForVerification()), $hash)) {
            throw new InvalidVerificationLinkException;
        }

        if ($user->hasVerifiedEmail()) {
            return;
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }
    }
}
