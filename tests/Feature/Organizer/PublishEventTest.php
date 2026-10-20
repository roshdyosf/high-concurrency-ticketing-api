<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketTier;
use App\Models\User;

function pubOrganizer(): User
{
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();

    return $user;
}

/**
 * @return array<string, string>
 */
function pubHeaders(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

function pubEvent(User $organizer, array $attributes = []): Event
{
    return Event::factory()->create(array_merge([
        'organizer_id' => $organizer->id,
        'status' => EventStatus::Draft,
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(4),
    ], $attributes));
}

function pubTier(Event $event): TicketTier
{
    return TicketTier::factory()->create(['event_id' => $event->id]);
}

function pubUrl(int $eventId): string
{
    return "/api/v1/organizer/events/{$eventId}/publish";
}

it('publishes a draft event that has a tier', function () {
    $organizer = pubOrganizer();
    $event = pubEvent($organizer);
    pubTier($event);

    $this->postJson(pubUrl($event->id), [], pubHeaders($organizer))
        ->assertOk()
        ->assertJsonPath('data.status', 'published');

    expect($event->fresh()->status)->toBe(EventStatus::Published);
});

it('rejects publishing an event with no tiers', function () {
    $organizer = pubOrganizer();
    $event = pubEvent($organizer);

    $this->postJson(pubUrl($event->id), [], pubHeaders($organizer))
        ->assertStatus(422)
        ->assertJsonPath('code', 'EVENT_HAS_NO_TIERS');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('rejects publishing an event that is not a draft', function (EventStatus $status) {
    $organizer = pubOrganizer();
    $event = pubEvent($organizer, ['status' => $status]);
    pubTier($event);

    $this->postJson(pubUrl($event->id), [], pubHeaders($organizer))
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVENT_NOT_PUBLISHABLE');

    expect($event->fresh()->status)->toBe($status);
})->with([
    'published' => EventStatus::Published,
    'completed' => EventStatus::Completed,
    'cancelled' => EventStatus::Cancelled,
    'suspended' => EventStatus::Suspended,
]);

it('rejects publishing an event whose start date has passed', function () {
    $organizer = pubOrganizer();
    $event = pubEvent($organizer, [
        'event_date' => now()->subHour(),
        'end_date' => now()->addHour(),
    ]);
    pubTier($event);

    $this->postJson(pubUrl($event->id), [], pubHeaders($organizer))
        ->assertStatus(422)
        ->assertJsonPath('code', 'EVENT_ALREADY_STARTED');

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('forbids publishing another organizer event', function () {
    $owner = pubOrganizer();
    $event = pubEvent($owner);
    pubTier($event);

    $this->postJson(pubUrl($event->id), [], pubHeaders(pubOrganizer()))
        ->assertForbidden();

    expect($event->fresh()->status)->toBe(EventStatus::Draft);
});

it('returns 404 for an unknown event', function () {
    $this->postJson(pubUrl(999999), [], pubHeaders(pubOrganizer()))
        ->assertNotFound();
});

it('forbids a customer from publishing', function () {
    $owner = pubOrganizer();
    $event = pubEvent($owner);
    pubTier($event);

    $this->postJson(pubUrl($event->id), [], pubHeaders(User::factory()->create()))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN_ROLE');
});

it('requires authentication', function () {
    $this->postJson(pubUrl(1))->assertUnauthorized();
});
