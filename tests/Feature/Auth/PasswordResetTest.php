<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

function prtUser(array $attrs = []): User
{
    $user = User::factory()->create();
    $user->forceFill(array_merge(['password' => Hash::make('old-password-1')], $attrs))->save();

    return $user->fresh();
}

function prtToken(User $user): string
{
    return Password::createToken($user);
}

function prtReset(User $user, string $token, string $password = 'new-password-1'): array
{
    return ['token' => $token, 'email' => $user->email, 'password' => $password];
}

it('sends a reset notification to an existing user', function () {
    Notification::fake();
    $user = prtUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('answers an unknown email exactly like a known one and sends nothing', function () {
    Notification::fake();
    $user = prtUser();

    $known = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = $this->postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.com']);

    expect($unknown->status())->toBe($known->status())
        ->and($unknown->json())->toEqual($known->json());

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
    Notification::assertCount(1);
});

it('points the reset link to the frontend', function () {
    Notification::fake();
    $user = prtUser();

    $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
        $url = $notification->toMail($user)->actionUrl;
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return str_starts_with($url, config('app.frontend_url') . '/reset-password?')
            && ($query['email'] ?? null) === $user->email
            && ! empty($query['token']);
    });
});

it('does not send a second link inside the throttle window but answers the same', function () {
    Notification::fake();
    $user = prtUser();

    $first = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $second = $this->postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);

    $first->assertOk();
    $second->assertOk();
    expect($second->json())->toEqual($first->json());

    Notification::assertSentToTimes($user, ResetPassword::class, 1);
});

it('resets the password and the new one works while the old one stops working', function () {
    $user = prtUser();
    $token = prtToken($user);

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, $token))->assertOk();

    expect(Hash::check('new-password-1', $user->fresh()->password))->toBeTrue();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'old-password-1'])
        ->assertStatus(422);
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-1'])
        ->assertOk();
});

it('deletes all the user tokens on a successful reset', function () {
    $user = prtUser();
    $user->createToken('one');
    $user->createToken('two');
    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(2);

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, prtToken($user)))->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $user->id)->count())->toBe(0);
});

it('does not touch the tokens of other users', function () {
    $user = prtUser();
    $other = prtUser();
    $other->createToken('keep');

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, prtToken($user)))->assertOk();

    expect(DB::table('personal_access_tokens')->where('tokenable_id', $other->id)->count())->toBe(1);
});

it('rejects a reset token that was already used', function () {
    $user = prtUser();
    $token = prtToken($user);

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, $token))->assertOk();
    $this->postJson('/api/v1/auth/reset-password', prtReset($user, $token, 'another-password-2'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
});

it('rejects a wrong token with 422 on email', function () {
    $user = prtUser();
    prtToken($user);

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, 'not-the-token'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(Hash::check('old-password-1', $user->fresh()->password))->toBeTrue();
});

it('rejects a valid token used with another email', function () {
    $user = prtUser();
    $other = prtUser();
    $token = prtToken($user);

    $this->postJson('/api/v1/auth/reset-password', prtReset($other, $token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(Hash::check('old-password-1', $other->fresh()->password))->toBeTrue();
});

it('rejects an unknown email with the same 422 on email', function () {
    $user = prtUser();
    $token = prtToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => 'nobody@example.com',
        'password' => 'new-password-1',
    ])->assertStatus(422)->assertJsonValidationErrors('email');
});

it('rejects an expired token', function () {
    $user = prtUser();
    $token = prtToken($user);

    $this->travel(61)->minutes();

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, $token))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');

    expect(Hash::check('old-password-1', $user->fresh()->password))->toBeTrue();
});

it('validates the reset payload', function (array $override, string $field) {
    $user = prtUser();
    $payload = array_merge(prtReset($user, prtToken($user)), $override);

    $this->postJson('/api/v1/auth/reset-password', $payload)
        ->assertStatus(422)
        ->assertJsonValidationErrors($field);
})->with([
    'missing token' => [['token' => ''], 'token'],
    'missing email' => [['email' => ''], 'email'],
    'missing password' => [['password' => ''], 'password'],
    'short password' => [['password' => 'short'], 'password'],
]);

it('lets a banned user reset the password but login still rejects them', function () {
    $user = prtUser(['is_banned' => true, 'banned_at' => now()]);

    $this->postJson('/api/v1/auth/reset-password', prtReset($user, prtToken($user)))->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'new-password-1'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'ACCOUNT_BANNED');
});
