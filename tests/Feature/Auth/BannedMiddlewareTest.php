<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
 * C9: global not.banned middleware (spec sections 2, 7, 13).
 */

function bannedUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'is_banned' => true,
        'ban_reason' => 'test',
        'banned_at' => now(),
    ], $attributes));
}

function bearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

it('blocks a banned user with a valid token on protected routes', function (string $method, string $uri) {
    $user = bannedUser();

    $this->json($method, $uri, [], bearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');
})->with([
    'me' => ['GET', '/api/v1/auth/me'],
    'logout' => ['POST', '/api/v1/auth/logout'],
    'resend verification' => ['POST', '/api/v1/auth/email/verification-notification'],
]);

it('lets a normal user through', function () {
    $user = User::factory()->create(['is_banned' => false]);

    $this->getJson('/api/v1/auth/me', bearer($user))->assertOk();
});

it('applies immediately when a user is banned and lifts on unban (same token)', function () {
    $user = User::factory()->create(['is_banned' => false]);
    $headers = bearer($user);

    $this->getJson('/api/v1/auth/me', $headers)->assertOk();

    $user->forceFill(['is_banned' => true])->save();
    app('auth')->forgetGuards(); // test-only: simulates a new HTTP request
    $this->getJson('/api/v1/auth/me', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');

    $user->forceFill(['is_banned' => false])->save();
    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', $headers)->assertOk();
});

it('runs before the role middleware', function () {
    Route::middleware(['auth:sanctum', 'not.banned', 'role:admin'])
        ->get('/api/_t/banned-admin', fn () => ['ok' => true]);

    $user = bannedUser(['role' => \App\Enums\UserRole::Customer]);

    $this->getJson('/api/_t/banned-admin', bearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');
});

it('still returns 401 without a token', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});

// ---- exempt routes ----

it('rejects a banned user at login itself and issues no token', function () {
    bannedUser([
        'email' => 'banned@test.local',
        'password' => Hash::make('Password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'banned@test.local',
        'password' => 'Password123',
    ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_BANNED');

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('does not block password reset requests for banned users', function () {
    bannedUser(['email' => 'banned@test.local']);

    $this->postJson('/api/v1/auth/forgot-password', ['email' => 'banned@test.local'])
        ->assertOk();
});

it('does not block registration', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'new@test.local',
        'password' => 'Password123',
    ])->assertCreated();
});
