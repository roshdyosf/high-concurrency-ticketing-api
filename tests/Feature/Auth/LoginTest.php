<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/*
 * C3: POST /api/v1/auth/login (spec sections 2, 7, 11).
 */

it('logs in and returns a bearer token with the user', function () {
    User::factory()->create([
        'email' => 'login@test.local',
        'password' => Hash::make('Password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'login@test.local',
        'password' => 'Password123',
    ])
        ->assertOk()
        ->assertJsonPath('data.token_type', 'Bearer')
        ->assertJsonPath('data.user.email', 'login@test.local')
        ->assertJsonStructure(['data' => ['token', 'token_type', 'user']])
        ->assertJsonMissingPath('data.user.data.is_banned');
});

it('returns a token that works on a protected route', function () {
    User::factory()->create([
        'email' => 'works@test.local',
        'password' => Hash::make('Password123'),
    ]);

    $token = $this->postJson('/api/v1/auth/login', [
        'email' => 'works@test.local',
        'password' => 'Password123',
    ])->json('data.token');

    $this->getJson('/api/v1/auth/me', ['Authorization' => 'Bearer ' . $token])
        ->assertOk()
        ->assertJsonPath('data.email', 'works@test.local');
});

it('matches the email case-insensitively', function () {
    User::factory()->create([
        'email' => 'case@test.local',
        'password' => Hash::make('Password123'),
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'CASE@Test.Local',
        'password' => 'Password123',
    ])->assertOk();
});

it('gives the same 422 for a wrong password and an unknown email', function () {
    User::factory()->create([
        'email' => 'exists@test.local',
        'password' => Hash::make('Password123'),
    ]);

    $wrongPassword = $this->postJson('/api/v1/auth/login', [
        'email' => 'exists@test.local',
        'password' => 'WrongPassword',
    ]);

    $unknownEmail = $this->postJson('/api/v1/auth/login', [
        'email' => 'ghost@test.local',
        'password' => 'Password123',
    ]);

    $wrongPassword->assertUnprocessable()->assertJsonValidationErrors('email');
    $unknownEmail->assertUnprocessable()->assertJsonValidationErrors('email');

    expect($wrongPassword->json('errors.email'))->toBe($unknownEmail->json('errors.email'));
});

it('does not reveal a ban to someone who does not know the password', function () {
    User::factory()->create([
        'email' => 'secretban@test.local',
        'password' => Hash::make('Password123'),
        'is_banned' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'secretban@test.local',
        'password' => 'WrongPassword',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects a banned account with the correct password and issues no token', function () {
    User::factory()->create([
        'email' => 'banned@test.local',
        'password' => Hash::make('Password123'),
        'is_banned' => true,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'banned@test.local',
        'password' => 'Password123',
    ])->assertForbidden()->assertJsonPath('code', 'ACCOUNT_BANNED');

    expect(DB::table('personal_access_tokens')->count())->toBe(0);
});

it('validates the payload', function (array $payload, string $field) {
    $this->postJson('/api/v1/auth/login', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);
})->with([
    'missing email' => [['password' => 'Password123'], 'email'],
    'invalid email' => [['email' => 'nope', 'password' => 'Password123'], 'email'],
    'missing password' => [['email' => 'a@test.local'], 'password'],
]);
