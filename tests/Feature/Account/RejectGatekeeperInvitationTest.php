<?php

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Models\Event;
use App\Models\EventGatekeeper;
use App\Models\User;
use App\Services\GatekeeperAccessService;

/**
 * @return array<string, string>
 */
function girHeaders(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

function girOrganizer(): User
{
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();

    return $user;
}

function girEvent(?User $organizer = null, EventStatus $status = EventStatus::Published): Event
{
    return Event::factory()->create([
        'organizer_id' => ($organizer ?? User::factory()->create())->id,
        'status' => $status,
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(4),
    ]);
}

function girRow(Event $event, User $invitee, GatekeeperInvitationStatus $status = GatekeeperInvitationStatus::Pending, bool $revoked = false): EventGatekeeper
{
    $row = new EventGatekeeper();
    $row->forceFill([
        'event_id' => $event->id,
        'user_id' => $invitee->id,
        'assigned_by' => $event->organizer_id,
        'assigned_at' => now(),
        'status' => $status,
        'revoked_at' => $revoked ? now() : null,
    ])->save();

    return $row;
}

function girUrl(int $id): string
{
    return "/api/v1/gatekeeper-invitations/{$id}/reject";
}

it('rejects a pending invitation', function () {
    $user = User::factory()->create();
    $row = girRow(girEvent(), $user);

    $this->postJson(girUrl($row->id), [], girHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.id', $row->id)
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.event.id', $row->event_id);

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Rejected);
});

it('does not grant scan access after rejecting', function () {
    $user = User::factory()->create();
    $event = girEvent();
    $row = girRow($event, $user);

    $this->postJson(girUrl($row->id), [], girHeaders($user))->assertOk();

    expect(app(GatekeeperAccessService::class)->canScan($user->id, $event->id))->toBeFalse();
});

it('removes the invitation from the pending list after rejecting', function () {
    $user = User::factory()->create();
    $row = girRow(girEvent(), $user);

    $this->postJson(girUrl($row->id), [], girHeaders($user))->assertOk();

    $this->getJson('/api/v1/gatekeeper-invitations', girHeaders($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('forbids rejecting an invitation of another user', function () {
    $row = girRow(girEvent(), User::factory()->create());

    $this->postJson(girUrl($row->id), [], girHeaders(User::factory()->create()))
        ->assertForbidden();

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});

it('returns 404 for an unknown invitation', function () {
    $this->postJson(girUrl(999999), [], girHeaders(User::factory()->create()))
        ->assertNotFound();
});

it('refuses an invitation that is not an active pending one', function (GatekeeperInvitationStatus $status, bool $revoked) {
    $user = User::factory()->create();
    $row = girRow(girEvent(), $user, $status, $revoked);

    $this->postJson(girUrl($row->id), [], girHeaders($user))
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVITATION_NOT_PENDING');

    expect($row->fresh()->status)->toBe($status);
})->with([
    'accepted' => [GatekeeperInvitationStatus::Accepted, false],
    'rejected' => [GatekeeperInvitationStatus::Rejected, false],
    'revoked' => [GatekeeperInvitationStatus::Pending, true],
]);

it('refuses an invitation of a completed event', function () {
    $user = User::factory()->create();
    $row = girRow(girEvent(null, EventStatus::Completed), $user);

    $this->postJson(girUrl($row->id), [], girHeaders($user))
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVITATION_EVENT_COMPLETED');

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});

it('refuses rejecting twice', function () {
    $user = User::factory()->create();
    $row = girRow(girEvent(), $user);
    $headers = girHeaders($user);

    $this->postJson(girUrl($row->id), [], $headers)->assertOk();

    $this->postJson(girUrl($row->id), [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVITATION_NOT_PENDING');
});

it('lets the organizer invite the same user again after a rejection', function () {
    $organizer = girOrganizer();
    $event = girEvent($organizer);
    $invitee = User::factory()->create();
    $row = girRow($event, $invitee);

    $this->postJson(girUrl($row->id), [], girHeaders($invitee))->assertOk();

    $this->postJson("/api/v1/organizer/events/{$event->id}/gatekeepers", ['email' => $invitee->email], girHeaders($organizer))
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending');

    expect(EventGatekeeper::query()->where('event_id', $event->id)->count())->toBe(2);
});

it('requires authentication', function () {
    $this->postJson(girUrl(1))->assertUnauthorized();
});

it('rejects a banned user', function () {
    $user = User::factory()->create();
    $row = girRow(girEvent(), $user);
    $headers = girHeaders($user);
    $user->forceFill(['is_banned' => true, 'ban_reason' => 'test', 'banned_at' => now()])->save();

    $this->postJson(girUrl($row->id), [], $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});
