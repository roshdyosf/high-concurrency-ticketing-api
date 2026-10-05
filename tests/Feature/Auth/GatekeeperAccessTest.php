<?php

use App\Enums\EventStatus;
use App\Enums\GatekeeperInvitationStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\EventGatekeeper;
use App\Models\User;
use App\Services\GatekeeperAccessService;
use Illuminate\Support\Carbon;

/*
 * C12: gatekeeper scope check (spec section 6).
 * Access = accepted, non-revoked assignment on a running event.
 * No role is involved: a plain customer can be a gatekeeper.
 */

function gkUser(UserRole $role = UserRole::Customer): User
{
    $user = new User();
    $user->forceFill([
        'name' => 'User ' . fake()->unique()->numerify('####'),
        'email' => fake()->unique()->safeEmail(),
        'password' => 'Password123',
        'role' => $role,
        'is_approved' => true,
    ])->save();

    return $user;
}

function gkEvent(?Carbon $start = null, ?Carbon $end = null): Event
{
    $start ??= now()->addDays(2);
    $end ??= $start->copy()->addHours(6);

    $event = new Event();
    $event->fill([
        'title' => 'Gate Event',
        'description' => 'Description',
        'venue_name' => 'Venue',
        'location' => 'Cairo',
        'event_date' => $start,
        'end_date' => $end,
    ]);
    $event->organizer_id = gkUser(UserRole::Organizer)->id;
    $event->save();

    return $event;
}

function gkAssign(
    Event $event,
    User $user,
    GatekeeperInvitationStatus $status = GatekeeperInvitationStatus::Accepted,
    ?Carbon $revokedAt = null,
): EventGatekeeper {
    $row = new EventGatekeeper();
    $row->forceFill([
        'event_id' => $event->id,
        'user_id' => $user->id,
        'assigned_by' => $event->organizer_id,
        'assigned_at' => now(),
        'status' => $status,
        'revoked_at' => $revokedAt,
    ])->save();

    return $row;
}

function canScan(User $user, Event $event): bool
{
    return app(GatekeeperAccessService::class)->canScan($user->id, $event->id);
}

it('authorizes an accepted, non-revoked gatekeeper (plain customer, no role needed)', function () {
    $event = gkEvent();
    $user = gkUser(UserRole::Customer);
    gkAssign($event, $user);

    expect(canScan($user, $event))->toBeTrue();
});

it('denies a user with no assignment', function () {
    expect(canScan(gkUser(), gkEvent()))->toBeFalse();
});

it('denies a pending, rejected or revoked assignment', function (GatekeeperInvitationStatus $status, bool $revoked) {
    $event = gkEvent();
    $user = gkUser();
    gkAssign($event, $user, $status, $revoked ? now()->subMinute() : null);

    expect(canScan($user, $event))->toBeFalse();
})->with([
    'pending' => [GatekeeperInvitationStatus::Pending, false],
    'rejected' => [GatekeeperInvitationStatus::Rejected, false],
    'accepted but revoked' => [GatekeeperInvitationStatus::Accepted, true],
]);

it('does not leak access to a different event', function () {
    $assigned = gkEvent();
    $other = gkEvent();
    $user = gkUser();
    gkAssign($assigned, $user);

    expect(canScan($user, $assigned))->toBeTrue()
        ->and(canScan($user, $other))->toBeFalse();
});

it('does not leak access to a different user', function () {
    $event = gkEvent();
    gkAssign($event, gkUser());

    expect(canScan(gkUser(), $event))->toBeFalse();
});

it('denies access after end_date even before the revocation job runs', function () {
    $event = gkEvent(now()->subHours(8), now()->subHours(2));
    $user = gkUser();
    gkAssign($event, $user);

    expect(canScan($user, $event))->toBeFalse();
});

it('allows access after event_date while the event is still running (late-comers)', function () {
    $event = gkEvent(now()->subHour(), now()->addHours(5));
    $user = gkUser();
    gkAssign($event, $user);

    expect(canScan($user, $event))->toBeTrue();
});

it('denies access once the event is completed', function () {
    $event = gkEvent();
    $event->forceFill(['status' => EventStatus::Completed])->save();
    $user = gkUser();
    gkAssign($event, $user);

    expect(canScan($user, $event))->toBeFalse();
});

it('authorizes a re-issued invitation after an earlier rejection', function () {
    $event = gkEvent();
    $user = gkUser();
    gkAssign($event, $user, GatekeeperInvitationStatus::Rejected);
    gkAssign($event, $user, GatekeeperInvitationStatus::Accepted);

    expect(canScan($user, $event))->toBeTrue();
});
