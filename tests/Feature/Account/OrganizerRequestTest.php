<?php

use App\Enums\UserRole;
use App\Models\User;

const ORQ_URL = '/api/v1/account/organizer-request';

function orqUser(UserRole $role = UserRole::Customer, array $attrs = []): User
{
    $user = User::factory()->create();
    $user->forceFill(array_merge(['role' => $role], $attrs))->save();

    return $user->fresh();
}

function orqHeaders(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

it('turns a verified customer into a pending organizer', function () {
    $user = orqUser();

    $this->postJson(ORQ_URL, [], orqHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    $fresh = $user->fresh();
    expect($fresh->role)->toBe(UserRole::Organizer)
        ->and($fresh->is_approved)->toBeFalse();
});

it('clears the rejection reason when a rejected customer re-applies', function () {
    $user = orqUser(UserRole::Customer, [
        'organizer_rejection_reason' => 'Incomplete profile',
        'organizer_reviewed_at' => now(),
    ]);

    $this->postJson(ORQ_URL, [], orqHeaders($user))->assertOk();

    $fresh = $user->fresh();
    expect($fresh->role)->toBe(UserRole::Organizer)
        ->and($fresh->is_approved)->toBeFalse()
        ->and($fresh->organizer_rejection_reason)->toBeNull();
});

it('returns 409 on a second request (pending organizer)', function () {
    $user = orqUser();
    $headers = orqHeaders($user);

    $this->postJson(ORQ_URL, [], $headers)->assertOk();

    app('auth')->forgetGuards();

    $this->postJson(ORQ_URL, [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORGANIZER_REQUEST_NOT_ALLOWED');
});

it('returns 409 for an approved organizer', function () {
    $user = orqUser(UserRole::Organizer, ['is_approved' => true]);

    $this->postJson(ORQ_URL, [], orqHeaders($user))
        ->assertStatus(409)
        ->assertJsonPath('code', 'ORGANIZER_REQUEST_NOT_ALLOWED');
});

it('returns 409 for an admin and leaves the role untouched', function () {
    $user = orqUser(UserRole::Admin);

    $this->postJson(ORQ_URL, [], orqHeaders($user))->assertStatus(409);

    expect($user->fresh()->role)->toBe(UserRole::Admin);
});

it('requires a verified email', function () {
    $user = orqUser(UserRole::Customer, ['email_verified_at' => null]);

    $this->postJson(ORQ_URL, [], orqHeaders($user))
        ->assertStatus(403)
        ->assertJsonPath('code', 'EMAIL_NOT_VERIFIED');

    expect($user->fresh()->role)->toBe(UserRole::Customer);
});

it('rejects unauthenticated requests with 401', function () {
    $this->postJson(ORQ_URL)->assertUnauthorized();
});

it('rejects banned users before anything else', function () {
    $user = orqUser(UserRole::Customer, ['is_banned' => true, 'banned_at' => now()]);

    $this->postJson(ORQ_URL, [], orqHeaders($user))
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_BANNED');

    expect($user->fresh()->role)->toBe(UserRole::Customer);
});
