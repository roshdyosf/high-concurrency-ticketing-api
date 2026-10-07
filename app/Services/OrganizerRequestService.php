<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\OrganizerRequestNotAllowedException;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrganizerRequestService
{
    public function submit(User $user): User
    {
        return DB::transaction(function () use ($user): User {
            $locked = User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            if ($locked->role !== UserRole::Customer) {
                throw new OrganizerRequestNotAllowedException();
            }

            $locked->role = UserRole::Organizer;
            $locked->is_approved = false;
            $locked->organizer_rejection_reason = null;
            $locked->save();

            return $locked;
        });
    }
}
