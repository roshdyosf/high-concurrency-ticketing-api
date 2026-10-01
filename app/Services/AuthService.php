<?php

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;

class AuthService
{
    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function register(array $data): User
    {
        $user = new User($data);
        $user->role = UserRole::Customer;
        $user->is_approved = true;
        $user->save();

        return $user;
    }
}
