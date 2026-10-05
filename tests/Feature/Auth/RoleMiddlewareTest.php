<?php

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/*
 * C8 / C10: role middleware (admin | organizer | customer).
 * The tests register their own throwaway routes, so nothing temporary
 * is needed in routes/api.php.
 */

beforeEach(function () {
    foreach (['admin', 'organizer', 'customer'] as $group) {
        Route::middleware(['auth:sanctum', "role:{$group}"])
            ->get("/api/_t/{$group}", fn () => ['ok' => true]);
    }
});

/** Sends a real Bearer-token request as $user (or as a guest when null). */
function getAs(?User $user, string $group)
{
    $headers = $user
        ? ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken]
        : [];

    return test()->getJson("/api/_t/{$group}", $headers);
}

dataset('role matrix', [
    'customer -> admin route' => [UserRole::Customer, true, 'admin', 403, 'FORBIDDEN_ROLE'],
    'customer -> organizer route' => [UserRole::Customer, true, 'organizer', 403, 'FORBIDDEN_ROLE'],
    'customer -> customer route' => [UserRole::Customer, true, 'customer', 200, null],

    'approved organizer -> admin route' => [UserRole::Organizer, true, 'admin', 403, 'FORBIDDEN_ROLE'],
    'approved organizer -> organizer route' => [UserRole::Organizer, true, 'organizer', 200, null],
    'approved organizer -> customer route' => [UserRole::Organizer, true, 'customer', 200, null],

    'pending organizer -> admin route' => [UserRole::Organizer, false, 'admin', 403, 'FORBIDDEN_ROLE'],
    'pending organizer -> organizer route' => [UserRole::Organizer, false, 'organizer', 403, 'ORGANIZER_NOT_APPROVED'],
    'pending organizer -> customer route (can still book)' => [UserRole::Organizer, false, 'customer', 200, null],

    'admin -> admin route' => [UserRole::Admin, true, 'admin', 200, null],
    'admin -> organizer route' => [UserRole::Admin, true, 'organizer', 403, 'FORBIDDEN_ROLE'],
    'admin -> customer route' => [UserRole::Admin, true, 'customer', 403, 'FORBIDDEN_ROLE'],
]);

it('enforces the role matrix', function (UserRole $role, bool $approved, string $group, int $status, ?string $code) {
    $user = User::factory()->create(['role' => $role, 'is_approved' => $approved]);

    $response = getAs($user, $group)->assertStatus($status);

    if ($code) {
        $response->assertJsonPath('code', $code);
    }
})->with('role matrix');

it('returns 401 without a token', function (string $group) {
    getAs(null, $group)->assertUnauthorized();
})->with(['admin', 'organizer', 'customer']);

it('reads is_approved from the database on every request', function () {
    $user = User::factory()->create(['role' => UserRole::Organizer, 'is_approved' => false]);
    $headers = ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];

    $this->getJson('/api/_t/organizer', $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'ORGANIZER_NOT_APPROVED');

    $user->forceFill(['is_approved' => true])->save();
    app('auth')->forgetGuards(); // test-only: simulates a brand new HTTP request

    $this->getJson('/api/_t/organizer', $headers)->assertOk();
});

it('reads the role from the database on every request', function () {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $headers = ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];

    $this->getJson('/api/_t/organizer', $headers)->assertForbidden();

    $user->forceFill(['role' => UserRole::Organizer, 'is_approved' => true])->save();
    app('auth')->forgetGuards();

    $this->getJson('/api/_t/organizer', $headers)->assertOk();
});

it('fails loudly on an unknown role group (gatekeeper is not a role)', function () {
    Route::middleware(['auth:sanctum', 'role:gatekeeper'])
        ->get('/api/_t/gatekeeper', fn () => ['ok' => true]);

    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->withoutExceptionHandling();

    expect(fn () => getAs($user, 'gatekeeper'))->toThrow(InvalidArgumentException::class);
});
