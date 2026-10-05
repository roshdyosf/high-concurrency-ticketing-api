<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/*
 * C2: POST /api/v1/auth/register (spec sections 2, 10.1, 11).
 */

it('registers a customer', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'New User',
        'email' => 'new@test.local',
        'password' => 'Password123',
    ])
        ->assertCreated()
        ->assertJsonPath('data.email', 'new@test.local')
        ->assertJsonPath('data.role', 'customer')
        ->assertJsonPath('data.is_approved', true)
        ->assertJsonPath('data.email_verified_at', null)
        ->assertJsonMissingPath('data.is_banned')
        ->assertJsonMissingPath('data.ban_reason')
        ->assertJsonMissingPath('data.password');
});

it('ignores any role sent in the body', function (string $role) {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Sneaky',
        'email' => 'sneaky@test.local',
        'password' => 'Password123',
        'role' => $role,
        'is_approved' => false,
        'is_banned' => true,
    ])->assertCreated()->assertJsonPath('data.role', 'customer');

    $user = User::where('email', 'sneaky@test.local')->firstOrFail();

    expect($user->role->value)->toBe('customer')
        ->and($user->is_approved)->toBeTrue()
        ->and($user->is_banned)->toBeFalse();
})->with(['admin', 'organizer', 'gatekeeper']);

it('lower-cases the email', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Mixed Case',
        'email' => 'MiXeD@Test.Local',
        'password' => 'Password123',
    ])->assertCreated()->assertJsonPath('data.email', 'mixed@test.local');

    expect(User::where('email', 'mixed@test.local')->exists())->toBeTrue();
});

it('rejects a duplicate email regardless of letter case', function () {
    User::factory()->create(['email' => 'taken@test.local']);

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dup',
        'email' => 'TAKEN@test.local',
        'password' => 'Password123',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('validates the payload', function (array $payload, string $field) {
    $this->postJson('/api/v1/auth/register', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing name' => [['email' => 'a@test.local', 'password' => 'Password123'], 'name'],
    'missing email' => [['name' => 'A', 'password' => 'Password123'], 'email'],
    'invalid email' => [['name' => 'A', 'email' => 'not-an-email', 'password' => 'Password123'], 'email'],
    'missing password' => [['name' => 'A', 'email' => 'a@test.local'], 'password'],
    'short password' => [['name' => 'A', 'email' => 'a@test.local', 'password' => 'short'], 'password'],
]);

it('stores the password hashed', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Hash Me',
        'email' => 'hash@test.local',
        'password' => 'Password123',
    ])->assertCreated();

    $stored = User::where('email', 'hash@test.local')->value('password');

    expect($stored)->not->toBe('Password123')
        ->and(Hash::check('Password123', $stored))->toBeTrue();
});

it('sends the verification email and leaves the account unverified', function () {
    Notification::fake();

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Verify Me',
        'email' => 'verify@test.local',
        'password' => 'Password123',
    ])->assertCreated();

    $user = User::where('email', 'verify@test.local')->firstOrFail();

    expect($user->email_verified_at)->toBeNull();
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('does not issue a token (the client logs in afterwards)', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'No Token',
        'email' => 'notoken@test.local',
        'password' => 'Password123',
    ])->assertCreated()->assertJsonMissingPath('data.token');

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});
