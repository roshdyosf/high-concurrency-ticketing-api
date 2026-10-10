<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Models\User;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function bksOrganizer(): array
{
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();

    return [$user, ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken]];
}

function bksUrl(TicketTier $tier): string
{
    return "/api/v1/organizer/tiers/{$tier->id}/seats/bulk";
}

it('generates rows by seats per row and updates the capacity', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 2, 'seats_per_row' => 3], $headers)
        ->assertCreated()
        ->assertJsonPath('data.id', $tier->id)
        ->assertJsonPath('data.total_capacity', 6);

    $seats = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->get();

    expect($seats)->toHaveCount(6)
        ->and($seats->pluck('row_label')->unique()->values()->all())->toBe(['A', 'B'])
        ->and($seats->where('row_label', 'A')->pluck('seat_number')->all())->toBe([1, 2, 3])
        ->and($seats->every(fn (Seat $seat) => $seat->status->value === 'available'))->toBeTrue()
        ->and($tier->fresh()->total_capacity)->toBe(6);
});

it('continues the row labels after Z with AA and AB', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 28, 'seats_per_row' => 1], $headers)->assertCreated();

    $labels = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->pluck('row_label')->all();

    expect($labels[0])->toBe('A')
        ->and($labels[25])->toBe('Z')
        ->and($labels[26])->toBe('AA')
        ->and($labels[27])->toBe('AB');
});

it('accepts the maximum size of 100 rows by 100 seats', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 100, 'seats_per_row' => 100], $headers)
        ->assertCreated()
        ->assertJsonPath('data.total_capacity', 10000);

    expect(Seat::where('ticket_tier_id', $tier->id)->count())->toBe(10000)
        ->and(Seat::where('ticket_tier_id', $tier->id)->orderByDesc('id')->value('row_label'))->toBe('CV');
});

it('validates the rows and seats per row limits', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), [], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['rows', 'seats_per_row']);

    foreach ([['rows' => 0, 'seats_per_row' => 5], ['rows' => 101, 'seats_per_row' => 5]] as $payload) {
        $this->postJson(bksUrl($tier), $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('rows');
    }

    foreach ([['rows' => 5, 'seats_per_row' => 0], ['rows' => 5, 'seats_per_row' => 101]] as $payload) {
        $this->postJson(bksUrl($tier), $payload, $headers)->assertUnprocessable()->assertJsonValidationErrors('seats_per_row');
    }

    expect(Seat::count())->toBe(0);
});

it('refuses a second generation for the same tier', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 1, 'seats_per_row' => 2], $headers)->assertCreated();

    $this->postJson(bksUrl($tier), ['rows' => 3, 'seats_per_row' => 3], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'SEATS_ALREADY_GENERATED');

    expect(Seat::where('ticket_tier_id', $tier->id)->count())->toBe(2)
        ->and($tier->fresh()->total_capacity)->toBe(2);
});

it('rejects a general admission tier', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->generalAdmission(50)->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 1, 'seats_per_row' => 2], $headers)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'TIER_NOT_SEATED');

    expect(Seat::count())->toBe(0);
});

it('rejects an event that is no longer editable', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->withStatus(EventStatus::Suspended)->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 1, 'seats_per_row' => 2], $headers)
        ->assertStatus(409)
        ->assertJsonPath('code', 'EVENT_NOT_EDITABLE');

    expect(Seat::count())->toBe(0);
});

it('forbids generating seats for another organizers tier', function () {
    [, $headers] = bksOrganizer();
    $tier = TicketTier::factory()->create();

    $this->postJson(bksUrl($tier), ['rows' => 1, 'seats_per_row' => 2], $headers)->assertForbidden();

    expect(Seat::count())->toBe(0);
});

it('returns not found for an unknown or non numeric tier id', function () {
    [, $headers] = bksOrganizer();

    $this->postJson('/api/v1/organizer/tiers/999999/seats/bulk', ['rows' => 1, 'seats_per_row' => 2], $headers)->assertNotFound();
    $this->postJson('/api/v1/organizer/tiers/abc/seats/bulk', ['rows' => 1, 'seats_per_row' => 2], $headers)->assertNotFound();
});

it('rejects a request without a token', function () {
    $tier = TicketTier::factory()->create();

    $this->postJson(bksUrl($tier), ['rows' => 1, 'seats_per_row' => 2])->assertUnauthorized();
});

it('shows the generated seats in the public seat map of a published event', function () {
    [$user, $headers] = bksOrganizer();
    $event = Event::factory()->published()->create(['organizer_id' => $user->id]);
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    $this->postJson(bksUrl($tier), ['rows' => 2, 'seats_per_row' => 4], $headers)->assertCreated();

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertOk()
        ->assertJsonCount(2, 'data.tiers.0.rows')
        ->assertJsonCount(4, 'data.tiers.0.rows.0.seats');
});
