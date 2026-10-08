<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = strtolower(trim((string) config('ticketing.admin.email')));
        $password = (string) config('ticketing.admin.password');

        if ($email === '' || $password === '') {
            $this->command->warn('ADMIN_EMAIL / ADMIN_PASSWORD not set; admin was not seeded.');
            return;
        }

        $admin = User::query()->firstOrNew(['email' => $email]);

        $admin->forceFill([
            'name' => 'Platform Admin',
            'password' => Hash::make($password),
            'role' => UserRole::Admin->value,
            'is_approved' => true,
            'is_banned' => false,
            'email_verified_at' => now(),
        ])->save();
    }
}
