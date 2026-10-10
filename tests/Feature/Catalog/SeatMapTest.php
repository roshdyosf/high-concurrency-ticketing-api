<?php

use App\Enums\EventStatus;
use App\Enums\SeatStatus;
use App\Models\Event;
use App\Models\Seat;
use App\Models\TicketTier;

it('returns the seats grouped by tier and row', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->withSeats(rows: 2, perRow: 3)
        ->create(['event_id' => $event->id, 'name' => 'VIP', 'price' => 50]);

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertOk()
        ->assertJsonPath('data.event_id', $event->id)
        ->assertJsonCount(1, 'data.tiers')
        ->assertJsonPath('data.tiers.0.id', $tier->id)
        ->assertJsonPath('data.tiers.0.name', 'VIP')
        ->assertJsonPath('data.tiers.0.price', '50.00')
        ->assertJsonCount(2, 'data.tiers.0.rows')
        ->assertJsonPath('data.tiers.0.rows.0.row_label', 'A')
        ->assertJsonCount(3, 'data.tiers.0.rows.0.seats')
        ->assertJsonPath('data.tiers.0.rows.0.seats.0.seat_number', 1)
        ->assertJsonPath('data.tiers.0.rows.0.seats.0.status', 'available')
        ->assertJsonMissingPath('data.tiers.0.rows.0.seats.0.ticket_tier_id');
});

it('leaves out general admission tiers', function () {
    $event = Event::factory()->published()->create();
    TicketTier::factory()->generalAdmission(50)->create(['event_id' => $event->id]);
    $seated = TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create(['event_id' => $event->id]);

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertOk()
        ->assertJsonCount(1, 'data.tiers')
        ->assertJsonPath('data.tiers.0.id', $seated->id);
});

it('reflects the held and booked statuses', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 3)->create(['event_id' => $event->id]);
    $seats = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->get();
    $seats[1]->forceFill(['status' => SeatStatus::Held])->save();
    $seats[2]->forceFill(['status' => SeatStatus::Booked])->save();

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertJsonPath('data.tiers.0.rows.0.seats.0.status', 'available')
        ->assertJsonPath('data.tiers.0.rows.0.seats.1.status', 'held')
        ->assertJsonPath('data.tiers.0.rows.0.seats.2.status', 'booked');
});

it('orders rows by length then alphabetically and seats by number', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);

    foreach ([['AA', 1], ['B', 3], ['A', 1], ['B', 1], ['B', 2]] as [$row, $number]) {
        Seat::factory()->create(['ticket_tier_id' => $tier->id, 'row_label' => $row, 'seat_number' => $number]);
    }

    $response = $this->getJson("/api/v1/events/{$event->id}/seats")->assertOk();

    expect(collect($response->json('data.tiers.0.rows'))->pluck('row_label')->all())->toBe(['A', 'B', 'AA'])
        ->and(collect($response->json('data.tiers.0.rows.1.seats'))->pluck('seat_number')->all())->toBe([1, 2, 3]);
});

it('returns an empty tiers list for an event without seated tiers', function () {
    $event = Event::factory()->published()->create();

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertOk()
        ->assertJsonPath('data.tiers', []);
});

it('does not include seats of another event', function () {
    $event = Event::factory()->published()->create();
    $mine = TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create(['event_id' => $event->id]);
    TicketTier::factory()->withSeats(rows: 1, perRow: 4)->create(['event_id' => Event::factory()->published()->create()->id]);

    $this->getJson("/api/v1/events/{$event->id}/seats")
        ->assertJsonCount(1, 'data.tiers')
        ->assertJsonPath('data.tiers.0.id', $mine->id)
        ->assertJsonCount(2, 'data.tiers.0.rows.0.seats');
});

it('hides draft and suspended events as not found', function () {
    foreach ([EventStatus::Draft, EventStatus::Suspended] as $status) {
        $event = Event::factory()->withStatus($status)->create();
        TicketTier::factory()->withSeats(rows: 1, perRow: 2)->create(['event_id' => $event->id]);

        $this->getJson("/api/v1/events/{$event->id}/seats")
            ->assertNotFound()
            ->assertJson(['code' => 'EVENT_NOT_FOUND']);
    }
});

it('returns not found for an unknown id', function () {
    $this->getJson('/api/v1/events/999999/seats')
        ->assertNotFound()
        ->assertJson(['code' => 'EVENT_NOT_FOUND']);
});

it('returns not found for a non numeric id', function () {
    $this->getJson('/api/v1/events/abc/seats')->assertNotFound();
});
