<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\Hash;
use App\Exceptions\AccountBannedException;
use App\Enums\UserRole;
use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;

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
    /**
     * @return array{token: string, user: User}
     */
    public function login(string $email, string $password): array
    {
        $user = User::where('email', $email)->first();
        if (!$user || !Hash::check($password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($user->is_banned) {
            throw new AccountBannedException();
        }

        $token = $user->createToken('auth')->plainTextToken;
        return [
            'token' => $token,
            'user' => $user,
        ];
    }

    public function logout(User $user): void
    {
        /** @var PersonalAccessToken|TransientToken|null $token */
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }
    }
}
