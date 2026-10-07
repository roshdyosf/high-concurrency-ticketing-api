<?php

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function evtUnverified(): User
{
    $user = User::factory()->create();
    $user->forceFill(['email_verified_at' => null])->save();

    return $user->fresh();
}

function evtUrl(User $user, ?string $hash = null, $expiry = null): string
{
    return URL::temporarySignedRoute(
        'verification.verify',
        $expiry ?? now()->addMinutes(60),
        ['id' => $user->id, 'hash' => $hash ?? sha1($user->email)]
    );
}

function evtBearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

it('verifies the email through a valid signed link without a token', function () {
    $user = evtUnverified();

    $this->getJson(evtUrl($user))->assertOk();

    expect($user->fresh()->email_verified_at)->not->toBeNull();
});

it('is idempotent when the email is already verified', function () {
    $user = evtUnverified();
    $url = evtUrl($user);

    $this->getJson($url)->assertOk();
    $verifiedAt = $user->fresh()->email_verified_at;

    $this->getJson($url)->assertOk();

    expect($user->fresh()->email_verified_at->equalTo($verifiedAt))->toBeTrue();
});

it('rejects a tampered signature', function () {
    $user = evtUnverified();

    $this->getJson(evtUrl($user) . 'x')->assertForbidden();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects an expired link', function () {
    $user = evtUnverified();

    $this->getJson(evtUrl($user, null, now()->subMinute()))->assertForbidden();

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects a validly signed link with a wrong hash', function () {
    $user = evtUnverified();

    $this->getJson(evtUrl($user, sha1('someone-else@example.com')))
        ->assertForbidden()
        ->assertJsonPath('code', 'INVALID_VERIFICATION_LINK');

    expect($user->fresh()->email_verified_at)->toBeNull();
});

it('rejects the hash of one user used with the id of another', function () {
    $victim = evtUnverified();
    $attacker = evtUnverified();

    $this->getJson(evtUrl($victim, sha1($attacker->email)))
        ->assertForbidden()
        ->assertJsonPath('code', 'INVALID_VERIFICATION_LINK');

    expect($victim->fresh()->email_verified_at)->toBeNull();
});

it('resends the verification notification to an unverified user', function () {
    Notification::fake();
    $user = evtUnverified();

    $this->postJson('/api/v1/auth/email/verification-notification', [], evtBearer($user))
        ->assertOk();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('returns 409 and sends nothing when already verified', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/email/verification-notification', [], evtBearer($user))
        ->assertStatus(409)
        ->assertJsonPath('code', 'EMAIL_ALREADY_VERIFIED');

    Notification::assertNothingSent();
});

it('requires authentication to resend', function () {
    $this->postJson('/api/v1/auth/email/verification-notification')->assertUnauthorized();
});
