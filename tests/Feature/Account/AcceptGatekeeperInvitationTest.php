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
function giaHeaders(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

function giaEvent(EventStatus $status = EventStatus::Published): Event
{
    $organizer = User::factory()->create();

    return Event::factory()->create([
        'organizer_id' => $organizer->id,
        'status' => $status,
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(4),
    ]);
}

function giaRow(Event $event, User $invitee, GatekeeperInvitationStatus $status = GatekeeperInvitationStatus::Pending, bool $revoked = false): EventGatekeeper
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

function giaUrl(int $id): string
{
    return "/api/v1/gatekeeper-invitations/{$id}/accept";
}

it('accepts a pending invitation', function () {
    $user = User::factory()->create();
    $row = giaRow(giaEvent(), $user);

    $this->postJson(giaUrl($row->id), [], giaHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.id', $row->id)
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.event.id', $row->event_id);

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Accepted);
});

it('grants scan access after accepting', function () {
    $user = User::factory()->create();
    $event = giaEvent();
    $row = giaRow($event, $user);

    $service = app(GatekeeperAccessService::class);
    expect($service->canScan($user->id, $event->id))->toBeFalse();

    $this->postJson(giaUrl($row->id), [], giaHeaders($user))->assertOk();

    expect($service->canScan($user->id, $event->id))->toBeTrue();
});

it('removes the invitation from the pending list after accepting', function () {
    $user = User::factory()->create();
    $row = giaRow(giaEvent(), $user);

    $this->postJson(giaUrl($row->id), [], giaHeaders($user))->assertOk();

    $this->getJson('/api/v1/gatekeeper-invitations', giaHeaders($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('forbids accepting an invitation of another user', function () {
    $row = giaRow(giaEvent(), User::factory()->create());

    $this->postJson(giaUrl($row->id), [], giaHeaders(User::factory()->create()))
        ->assertForbidden();

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});

it('returns 404 for an unknown invitation', function () {
    $this->postJson(giaUrl(999999), [], giaHeaders(User::factory()->create()))
        ->assertNotFound();
});

it('refuses an invitation that is not an active pending one', function (GatekeeperInvitationStatus $status, bool $revoked) {
    $user = User::factory()->create();
    $row = giaRow(giaEvent(), $user, $status, $revoked);

    $this->postJson(giaUrl($row->id), [], giaHeaders($user))
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
    $row = giaRow(giaEvent(EventStatus::Completed), $user);

    $this->postJson(giaUrl($row->id), [], giaHeaders($user))
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVITATION_EVENT_COMPLETED');

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});

it('refuses accepting twice', function () {
    $user = User::factory()->create();
    $row = giaRow(giaEvent(), $user);
    $headers = giaHeaders($user);

    $this->postJson(giaUrl($row->id), [], $headers)->assertOk();

    $this->postJson(giaUrl($row->id), [], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'INVITATION_NOT_PENDING');
});

it('requires authentication', function () {
    $this->postJson(giaUrl(1))->assertUnauthorized();
});

it('rejects a banned user', function () {
    $user = User::factory()->create();
    $row = giaRow(giaEvent(), $user);
    $headers = giaHeaders($user);
    $user->forceFill(['is_banned' => true, 'ban_reason' => 'test', 'banned_at' => now()])->save();

    $this->postJson(giaUrl($row->id), [], $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');

    expect($row->fresh()->status)->toBe(GatekeeperInvitationStatus::Pending);
});
