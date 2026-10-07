<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;

function lmBearer(string $token): array
{
    return ['Authorization' => 'Bearer ' . $token];
}

it('logout deletes only the current token', function () {
    $user = User::factory()->create();
    $first = $user->createToken('first')->plainTextToken;
    $second = $user->createToken('second')->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], lmBearer($first))->assertNoContent();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(1);

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', lmBearer($second))->assertOk();
});

it('rejects the revoked token on me with 401', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->postJson('/api/v1/auth/logout', [], lmBearer($token))->assertNoContent();

    app('auth')->forgetGuards();
    $this->getJson('/api/v1/auth/me', lmBearer($token))->assertUnauthorized();
});

it('me returns the user without sensitive fields', function () {
    $user = User::factory()->create();
    $token = $user->createToken('t')->plainTextToken;

    $this->getJson('/api/v1/auth/me', lmBearer($token))
        ->assertOk()
        ->assertJsonPath('data.id', $user->id)
        ->assertJsonPath('data.email', $user->email)
        ->assertJsonMissingPath('data.is_banned')
        ->assertJsonMissingPath('data.password');
});

it('logout and me require authentication', function () {
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});
