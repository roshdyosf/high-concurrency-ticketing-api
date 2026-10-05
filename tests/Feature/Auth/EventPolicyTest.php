<?php

use App\Enums\UserRole;
use App\Models\Event;
use App\Models\User;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/*
 * C11: EventPolicy::manage (spec section 2). Ownership only.
 */

beforeEach(function () {
    // Throwaway route mimicking the real organizer layout.
    Route::middleware([
        'auth:sanctum',
        'not.banned',
        'role:organizer',
        SubstituteBindings::class,
        'can:manage,event',
    ])->put('/api/_t/events/{event}', fn (Event $event) => ['id' => $event->id]);
});

function makeOrganizer(bool $approved = true): User
{
    $user = new User();
    $user->forceFill([
        'name' => 'Org ' . fake()->unique()->numerify('###'),
        'email' => fake()->unique()->safeEmail(),
        'password' => 'Password123',
        'role' => UserRole::Organizer,
        'is_approved' => $approved,
    ])->save();

    return $user;
}

function makeEvent(User $organizer): Event
{
    $event = new Event();
    $event->fill([
        'title' => 'Test Event',
        'description' => 'Description',
        'venue_name' => 'Venue',
        'location' => 'Cairo',
        'event_date' => now()->addDays(10),
        'end_date' => now()->addDays(10)->addHours(6),
    ]);
    $event->organizer_id = $organizer->id;
    $event->save();

    return $event;
}

function asBearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
}

it('lets the owning organizer manage their event', function () {
    $owner = makeOrganizer();
    $event = makeEvent($owner);

    $this->putJson("/api/_t/events/{$event->id}", [], asBearer($owner))
        ->assertOk()
        ->assertJsonPath('id', $event->id);
});

it('forbids another organizer from managing the event', function () {
    $event = makeEvent(makeOrganizer());
    $other = makeOrganizer();

    $this->putJson("/api/_t/events/{$event->id}", [], asBearer($other))
        ->assertForbidden();
});

it('returns 404 for a missing event', function () {
    $owner = makeOrganizer();

    $this->putJson('/api/_t/events/999999', [], asBearer($owner))
        ->assertNotFound();
});

it('checks the role/approval gate before ownership', function () {
    $pending = makeOrganizer(approved: false);
    $event = makeEvent($pending);

    $this->putJson("/api/_t/events/{$event->id}", [], asBearer($pending))
        ->assertForbidden()
        ->assertJsonPath('code', 'ORGANIZER_NOT_APPROVED');
});

it('returns 401 without a token', function () {
    $event = makeEvent(makeOrganizer());

    $this->putJson("/api/_t/events/{$event->id}")->assertUnauthorized();
});

it('is auto-discovered and decides purely on ownership', function () {
    $owner = makeOrganizer();
    $event = makeEvent($owner);

    $admin = new User();
    $admin->forceFill([
        'name' => 'Admin',
        'email' => 'admin-policy@test.local',
        'password' => 'Password123',
        'role' => UserRole::Admin,
        'is_approved' => true,
    ])->save();

    expect(Gate::forUser($owner)->allows('manage', $event))->toBeTrue()
        ->and(Gate::forUser(makeOrganizer())->allows('manage', $event))->toBeFalse()
        ->and(Gate::forUser($admin)->allows('manage', $event))->toBeFalse();
});
