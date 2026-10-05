<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * C14: verified-email middleware (spec sections 2 and 13).
 */

beforeEach(function () {
    Route::middleware(['auth:sanctum', 'not.banned', 'verified.email'])
        ->post('/api/_t/needs-verified', fn () => ['ok' => true]);
});

function evUser(bool $verified): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => $verified ? now() : null])->save();

    return $user;
}

function evBearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

it('blocks an unverified user with EMAIL_NOT_VERIFIED', function () {
    $this->postJson('/api/_t/needs-verified', [], evBearer(evUser(false)))
        ->assertForbidden()
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');
});

it('lets a verified user through', function () {
    $this->postJson('/api/_t/needs-verified', [], evBearer(evUser(true)))
        ->assertOk();
});

it('returns 401 without a token', function () {
    $this->postJson('/api/_t/needs-verified')->assertUnauthorized();
});

it('applies as soon as the email gets verified (same token)', function () {
    $user = evUser(false);
    $headers = evBearer($user);

    $this->postJson('/api/_t/needs-verified', [], $headers)->assertForbidden();

    $user->markEmailAsVerified();
    app('auth')->forgetGuards(); // test-only: simulates a new HTTP request

    $this->postJson('/api/_t/needs-verified', [], $headers)->assertOk();
});

it('checks the ban before the verification', function () {
    $user = evUser(false);
    $user->forceFill(['is_banned' => true])->save();

    $this->postJson('/api/_t/needs-verified', [], evBearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');
});
