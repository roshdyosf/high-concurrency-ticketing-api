<?php

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Models\Event;
use App\Models\EventGatekeeper;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * @return array<string, string>
 */
function gilHeaders(User $user): array
{
    app('auth')->forgetGuards();

    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

function gilEvent(string $title = 'Gala', EventStatus $status = EventStatus::Published): Event
{
    $organizer = User::factory()->create();

    return Event::factory()->create([
        'organizer_id' => $organizer->id,
        'title' => $title,
        'status' => $status,
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(4),
    ]);
}

function gilRow(
    Event $event,
    User $invitee,
    GatekeeperInvitationStatus $status = GatekeeperInvitationStatus::Pending,
    bool $revoked = false,
    ?Carbon $assignedAt = null,
): EventGatekeeper {
    $row = new EventGatekeeper();
    $row->forceFill([
        'event_id' => $event->id,
        'user_id' => $invitee->id,
        'assigned_by' => $event->organizer_id,
        'assigned_at' => $assignedAt ?? now(),
        'status' => $status,
        'revoked_at' => $revoked ? now() : null,
    ])->save();

    return $row;
}

const GIL_URL = '/api/v1/gatekeeper-invitations';

it('lists the pending invitations of the caller with event details', function () {
    $user = User::factory()->create();
    $event = gilEvent('Gala');
    $row = gilRow($event, $user);

    $this->getJson(GIL_URL, gilHeaders($user))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $row->id)
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.event.id', $event->id)
        ->assertJsonPath('data.0.event.title', 'Gala');
});

it('excludes rows that are not an active pending invitation', function (GatekeeperInvitationStatus $status, bool $revoked) {
    $user = User::factory()->create();
    gilRow(gilEvent(), $user, $status, $revoked);

    $this->getJson(GIL_URL, gilHeaders($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
})->with([
    'rejected' => [GatekeeperInvitationStatus::Rejected, false],
    'accepted' => [GatekeeperInvitationStatus::Accepted, false],
    'revoked' => [GatekeeperInvitationStatus::Pending, true],
]);

it('does not list invitations of other users', function () {
    $user = User::factory()->create();
    gilRow(gilEvent(), User::factory()->create());

    $this->getJson(GIL_URL, gilHeaders($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('excludes invitations of completed events', function () {
    $user = User::factory()->create();
    gilRow(gilEvent('Old', EventStatus::Completed), $user);

    $this->getJson(GIL_URL, gilHeaders($user))
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('orders invitations newest first', function () {
    $user = User::factory()->create();
    $older = gilRow(gilEvent('Older'), $user, assignedAt: now()->subDays(2));
    $newer = gilRow(gilEvent('Newer'), $user, assignedAt: now()->subDay());

    $this->getJson(GIL_URL, gilHeaders($user))
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id)
        ->assertJsonPath('data.1.id', $older->id);
});

it('returns an empty list when there are no invitations', function () {
    $this->getJson(GIL_URL, gilHeaders(User::factory()->create()))
        ->assertOk()
        ->assertExactJson(['data' => []]);
});

it('requires authentication', function () {
    $this->getJson(GIL_URL)->assertUnauthorized();
});

it('rejects a banned user', function () {
    $user = User::factory()->create();
    $headers = gilHeaders($user);
    $user->forceFill(['is_banned' => true, 'ban_reason' => 'test', 'banned_at' => now()])->save();

    $this->getJson(GIL_URL, $headers)
        ->assertForbidden()
        ->assertJsonPath('code', 'ACCOUNT_BANNED');
});
