<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;

function rlPost(string $uri, array $payload, string $ip)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->postJson($uri, $payload);
}

function rlLogin(string $email, string $ip)
{
    return rlPost('/api/v1/auth/login', ['email' => $email, 'password' => 'whatever-1'], $ip);
}

it('limits register to 5 per minute per IP', function () {
    foreach (range(1, 5) as $i) {
        rlPost('/api/v1/auth/register', [], '10.0.0.1')->assertStatus(422);
    }

    rlPost('/api/v1/auth/register', [], '10.0.0.1')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    rlPost('/api/v1/auth/register', [], '10.0.0.2')->assertStatus(422);
});

it('limits login to 5 per minute per IP even with different emails', function () {
    foreach (range(1, 5) as $i) {
        rlLogin("user{$i}@example.com", '10.0.0.1')->assertStatus(422);
    }

    rlLogin('user6@example.com', '10.0.0.1')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    rlLogin('user6@example.com', '10.0.0.2')->assertStatus(422);
});

it('shares one per-IP bucket between register and login', function () {
    foreach (range(1, 3) as $i) {
        rlPost('/api/v1/auth/register', [], '10.0.0.1')->assertStatus(422);
    }
    foreach (range(1, 2) as $i) {
        rlLogin("user{$i}@example.com", '10.0.0.1')->assertStatus(422);
    }

    rlLogin('user3@example.com', '10.0.0.1')->assertStatus(429);
});

it('limits login to 5 per minute per email across IPs, ignoring letter case', function () {
    $variants = ['Victim@Example.com', 'victim@example.com', 'VICTIM@example.com', 'victim@example.com', 'Victim@Example.com'];

    foreach ($variants as $i => $email) {
        rlLogin($email, "10.0.1.{$i}")->assertStatus(422);
    }

    rlLogin('victim@example.com', '10.0.1.99')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    rlLogin('someone-else@example.com', '10.0.1.99')->assertStatus(422);
});

it('limits authenticated routes to 60 per minute per user, not per IP', function () {
    $a = User::factory()->create();
    $b = User::factory()->create();
    $headersA = ['Authorization' => 'Bearer ' . $a->createToken('t')->plainTextToken];
    $headersB = ['Authorization' => 'Bearer ' . $b->createToken('t')->plainTextToken];

    foreach (range(1, 60) as $i) {
        $this->getJson('/api/v1/auth/me', $headersA)->assertOk();
    }

    $this->getJson('/api/v1/auth/me', $headersA)
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    app('auth')->forgetGuards();

    $this->getJson('/api/v1/auth/me', $headersB)->assertOk();
});

it('limits public GET routes to 120 per minute per IP', function () {
    Route::middleware('throttle:public')
        ->get('/api/v1/_rl/public', fn () => response()->json(['ok' => true]));

    foreach (range(1, 120) as $i) {
        test()->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])->getJson('/api/v1/_rl/public')->assertOk();
    }

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.2.1'])->getJson('/api/v1/_rl/public')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.2.2'])->getJson('/api/v1/_rl/public')->assertOk();
});

it('limits forgot-password and reset-password to 5 per minute per IP in a bucket separate from login', function () {
    foreach (range(1, 5) as $i) {
        rlPost('/api/v1/auth/forgot-password', ['email' => "u{$i}@example.com"], '10.0.3.1')->assertOk();
    }

    rlPost('/api/v1/auth/forgot-password', ['email' => 'u6@example.com'], '10.0.3.1')
        ->assertStatus(429)
        ->assertHeader('Retry-After');

    rlPost('/api/v1/auth/reset-password', [], '10.0.3.1')->assertStatus(429);

    rlLogin('someone@example.com', '10.0.3.1')->assertStatus(422);
    rlPost('/api/v1/auth/forgot-password', ['email' => 'u1@example.com'], '10.0.3.2')->assertOk();
});

it('limits the email verification link to 120 per minute per IP', function () {
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => null])->save();
    $url = URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
        'id' => $user->id,
        'hash' => sha1($user->email),
    ]);
    $path = parse_url($url, PHP_URL_PATH) . '?' . parse_url($url, PHP_URL_QUERY);

    foreach (range(1, 120) as $i) {
        test()->withServerVariables(['REMOTE_ADDR' => '10.0.4.1'])->getJson($path)->assertOk();
    }

    test()->withServerVariables(['REMOTE_ADDR' => '10.0.4.1'])->getJson($path)->assertStatus(429);
});
