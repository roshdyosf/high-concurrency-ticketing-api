<?php

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function () {
    config([
        'ticketing.admin.email' => 'Admin@Example.com',
        'ticketing.admin.password' => 'secret-pass-123',
    ]);
});

it('creates a verified, approved, unbanned admin from config', function () {
    $this->seed(AdminSeeder::class);

    $this->assertDatabaseHas('users', [
        'email' => 'admin@example.com',
        'role' => 'admin',
        'is_approved' => true,
        'is_banned' => false,
    ]);

    $admin = User::where('email', 'admin@example.com')->firstOrFail();

    expect($admin->email_verified_at)->not->toBeNull()
        ->and(Hash::check('secret-pass-123', $admin->password))->toBeTrue();
});

it('is idempotent when run twice', function () {
    $this->seed(AdminSeeder::class);
    $this->seed(AdminSeeder::class);

    expect(User::where('email', 'admin@example.com')->count())->toBe(1);
});

it('does nothing when credentials are missing', function () {
    config(['ticketing.admin.email' => null, 'ticketing.admin.password' => null]);

    $this->seed(AdminSeeder::class);

    expect(User::count())->toBe(0);
});

it('lets the seeded admin log in through the API', function () {
    $this->seed(AdminSeeder::class);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'admin@example.com',
        'password' => 'secret-pass-123',
    ])->assertOk();
});
