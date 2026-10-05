<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;

/*
 * C10: organizer approval gate (spec sections 2, 7, 10.1).
 * Unapproved organizer: 403 on every /organizer route; customer routes
 * and login are unaffected.
 */

beforeEach(function () {
    // Throwaway routes that mimic the real /organizer group layout.
    Route::prefix('api/_t/organizer')
        ->middleware(['auth:sanctum', 'not.banned', 'role:organizer'])
        ->group(function () {
            Route::get('events', fn () => ['ok' => true]);
            Route::post('events', fn () => ['ok' => true]);
            Route::put('events/{id}', fn () => ['ok' => true]);
            Route::delete('events/{id}', fn () => ['ok' => true]);
        });
});

function organizerBearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

dataset('organizer routes', [
    'list' => ['GET', '/api/_t/organizer/events'],
    'create' => ['POST', '/api/_t/organizer/events'],
    'update' => ['PUT', '/api/_t/organizer/events/1'],
    'delete' => ['DELETE', '/api/_t/organizer/events/1'],
]);

it('blocks an unapproved organizer on every organizer route', function (string $method, string $uri) {
    $user = User::factory()->create(['role' => UserRole::Organizer, 'is_approved' => false]);

    $this->json($method, $uri, [], organizerBearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'ORGANIZER_NOT_APPROVED');
})->with('organizer routes');

it('lets an approved organizer through on every organizer route', function (string $method, string $uri) {
    $user = User::factory()->create(['role' => UserRole::Organizer, 'is_approved' => true]);

    $this->json($method, $uri, [], organizerBearer($user))->assertOk();
})->with('organizer routes');

it('lets an unapproved organizer log in', function () {
    User::factory()->create([
        'email' => 'pending@test.local',
        'password' => Hash::make('Password123'),
        'role' => UserRole::Organizer,
        'is_approved' => false,
    ]);

    $this->postJson('/api/v1/auth/login', [
        'email' => 'pending@test.local',
        'password' => 'Password123',
    ])->assertOk()->assertJsonStructure(['token', 'token_type', 'user']);
});

it('keeps customer-level routes available to an unapproved organizer', function () {
    $user = User::factory()->create(['role' => UserRole::Organizer, 'is_approved' => false]);

    $this->getJson('/api/v1/auth/me', organizerBearer($user))
        ->assertOk()
        ->assertJsonPath('data.role', 'organizer')
        ->assertJsonPath('data.is_approved', false);
});

it('does not apply the gate to a plain customer (role error instead)', function () {
    // A customer has is_approved = true by default; the role check fails first.
    $user = User::factory()->create(['role' => UserRole::Customer]);

    $this->getJson('/api/_t/organizer/events', organizerBearer($user))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN_ROLE');
});
