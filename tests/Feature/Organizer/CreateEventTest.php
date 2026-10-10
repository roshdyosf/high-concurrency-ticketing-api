<?php

use App\Models\Event;
use App\Models\User;

const CEV_URL = '/api/v1/organizer/events';

function cevToken(string $state = 'organizer', bool $approved = true): string
{
    $user = User::factory()->{$state}()->create();
    $user->forceFill(['is_approved' => $approved])->save();

    return $user->createToken('test')->plainTextToken;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cevPayload(array $overrides = []): array
{
    return array_merge([
        'title' => 'Jazz Night',
        'description' => 'An evening of live jazz.',
        'venue_name' => 'Opera House',
        'location' => 'Cairo',
        'event_date' => now()->addDays(10)->toIso8601String(),
        'end_date' => now()->addDays(10)->addHours(3)->toIso8601String(),
    ], $overrides);
}

it('creates a draft event owned by the authenticated organizer', function () {
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();
    $token = $user->createToken('test')->plainTextToken;

    $this->postJson(CEV_URL, cevPayload(), ['Authorization' => "Bearer {$token}"])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Jazz Night')
        ->assertJsonPath('data.status', 'draft')
        ->assertJsonStructure(['data' => ['id', 'title', 'description', 'venue_name', 'location', 'event_date', 'end_date', 'image_url', 'status']]);

    $event = Event::firstOrFail();

    expect($event->organizer_id)->toBe($user->id)
        ->and($event->status->value)->toBe('draft');
});

it('ignores status and organizer_id sent by the client', function () {
    $other = User::factory()->organizer()->create();
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();
    $token = $user->createToken('test')->plainTextToken;

    $this->postJson(CEV_URL, cevPayload(['status' => 'published', 'organizer_id' => $other->id]), ['Authorization' => "Bearer {$token}"])
        ->assertCreated()
        ->assertJsonPath('data.status', 'draft');

    $event = Event::firstOrFail();

    expect($event->organizer_id)->toBe($user->id)
        ->and($event->status->value)->toBe('draft');
});

it('requires every field', function () {
    $this->postJson(CEV_URL, [], ['Authorization' => 'Bearer ' . cevToken()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['title', 'description', 'venue_name', 'location', 'event_date', 'end_date']);
});

it('requires end_date to be after event_date', function () {
    $start = now()->addDays(10);

    $this->postJson(CEV_URL, cevPayload([
        'event_date' => $start->toIso8601String(),
        'end_date' => $start->copy()->subHour()->toIso8601String(),
    ]), ['Authorization' => 'Bearer ' . cevToken()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('end_date');
});

it('requires event_date to be in the future', function () {
    $this->postJson(CEV_URL, cevPayload([
        'event_date' => now()->subDay()->toIso8601String(),
        'end_date' => now()->addHour()->toIso8601String(),
    ]), ['Authorization' => 'Bearer ' . cevToken()])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('event_date');
});

it('keeps the new draft out of the public catalog', function () {
    $this->postJson(CEV_URL, cevPayload(), ['Authorization' => 'Bearer ' . cevToken()])->assertCreated();

    $this->getJson('/api/v1/events')->assertOk()->assertJsonCount(0, 'data');
});

it('rejects a request without a token', function () {
    $this->postJson(CEV_URL, cevPayload())->assertUnauthorized();
});

it('rejects a customer with FORBIDDEN_ROLE', function () {
    $user = User::factory()->create();

    $this->postJson(CEV_URL, cevPayload(), ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken])
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN_ROLE');
});

it('rejects an unapproved organizer with ORGANIZER_NOT_APPROVED', function () {
    $this->postJson(CEV_URL, cevPayload(), ['Authorization' => 'Bearer ' . cevToken(approved: false)])
        ->assertForbidden()
        ->assertJsonPath('code', 'ORGANIZER_NOT_APPROVED');
});
