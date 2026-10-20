<?php

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Models\Event;
use App\Models\EventGatekeeper;
use App\Models\User;

function igkOrganizer(): User
{
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();

    return $user;
}

/**
 * @return array<string, string>
 */
function igkHeaders(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

function igkEvent(User $organizer, EventStatus $status = EventStatus::Published): Event
{
    return Event::factory()->create([
        'organizer_id' => $organizer->id,
        'status' => $status,
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(4),
    ]);
}

function igkUrl(int $eventId): string
{
    return "/api/v1/organizer/events/{$eventId}/gatekeepers";
}

function igkRow(Event $event, User $invitee, User $organizer, GatekeeperInvitationStatus $status, bool $revoked = false): EventGatekeeper
{
    $row = new EventGatekeeper();
    $row->forceFill([
        'event_id' => $event->id,
        'user_id' => $invitee->id,
        'assigned_by' => $organizer->id,
        'assigned_at' => now(),
        'status' => $status,
        'revoked_at' => $revoked ? now() : null,
    ])->save();

    return $row;
}

it('invites a registered user as a pending gatekeeper', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.event_id', $event->id)
        ->assertJsonPath('data.user_id', $invitee->id);

    $row = EventGatekeeper::query()->where('event_id', $event->id)->where('user_id', $invitee->id)->firstOrFail();
    expect($row->assigned_by)->toBe($organizer->id)
        ->and($row->status)->toBe(GatekeeperInvitationStatus::Pending);
});

it('matches the email case-insensitively', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => '  ' . strtoupper($invitee->email) . ' '], igkHeaders($organizer))
        ->assertCreated()
        ->assertJsonPath('data.user_id', $invitee->id);
});

it('returns 404 when the email is not registered', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);

    $this->postJson(igkUrl($event->id), ['email' => 'nobody@example.com'], igkHeaders($organizer))
        ->assertNotFound()
        ->assertJsonPath('code', 'USER_NOT_FOUND');
});

it('refuses invitations for completed, cancelled or suspended events', function (EventStatus $status) {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer, $status);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVENT_NOT_INVITABLE');

    expect(EventGatekeeper::query()->count())->toBe(0);
})->with([
    'completed' => EventStatus::Completed,
    'cancelled' => EventStatus::Cancelled,
    'suspended' => EventStatus::Suspended,
]);

it('does not reveal unknown emails for a closed event', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer, EventStatus::Completed);

    $this->postJson(igkUrl($event->id), ['email' => 'nobody@example.com'], igkHeaders($organizer))
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVENT_NOT_INVITABLE');
});

it('allows invitations for draft and published events', function (EventStatus $status) {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer, $status);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertCreated();
})->with([
    'draft' => EventStatus::Draft,
    'published' => EventStatus::Published,
]);

it('refuses a duplicate active invitation', function (GatekeeperInvitationStatus $status) {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();
    igkRow($event, $invitee, $organizer, $status);

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertStatus(409)
        ->assertJsonPath('code', 'GATEKEEPER_ALREADY_INVITED');

    expect(EventGatekeeper::query()->count())->toBe(1);
})->with([
    'pending' => GatekeeperInvitationStatus::Pending,
    'accepted' => GatekeeperInvitationStatus::Accepted,
]);

it('allows re-inviting after a rejection or a revocation', function (GatekeeperInvitationStatus $status, bool $revoked) {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();
    igkRow($event, $invitee, $organizer, $status, $revoked);

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertCreated();

    expect(EventGatekeeper::query()->count())->toBe(2);
})->with([
    'rejected' => [GatekeeperInvitationStatus::Rejected, false],
    'revoked' => [GatekeeperInvitationStatus::Accepted, true],
]);

it('forbids inviting to another organizer event', function () {
    $owner = igkOrganizer();
    $event = igkEvent($owner);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders(igkOrganizer()))
        ->assertForbidden();

    expect(EventGatekeeper::query()->count())->toBe(0);
});

it('forbids a customer', function () {
    $event = igkEvent(igkOrganizer());
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders(User::factory()->create()))
        ->assertForbidden()
        ->assertJsonPath('code', 'FORBIDDEN_ROLE');
});

it('requires authentication', function () {
    $this->postJson(igkUrl(1), ['email' => 'a@example.com'])->assertUnauthorized();
});

it('validates the email field', function (array $payload) {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);

    $this->postJson(igkUrl($event->id), $payload, igkHeaders($organizer))
        ->assertStatus(422)
        ->assertJsonValidationErrors('email');
})->with([
    'missing' => [[]],
    'invalid' => [['email' => 'not-an-email']],
]);

it('limits invitations to 20 per hour per organizer', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();
    $headers = igkHeaders($organizer);

    for ($i = 0; $i < 20; $i++) {
        expect($this->postJson(igkUrl($event->id), ['email' => $invitee->email], $headers)->status())->not->toBe(429);
    }

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], $headers)->assertStatus(429);
});

it('uses only the invites limiter (not throttle:api)', function () {
    $organizer = igkOrganizer();
    $event = igkEvent($organizer);
    $invitee = User::factory()->create();

    $this->postJson(igkUrl($event->id), ['email' => $invitee->email], igkHeaders($organizer))
        ->assertHeader('X-RateLimit-Limit', '20')
        ->assertHeader('X-RateLimit-Remaining', '19');
});
