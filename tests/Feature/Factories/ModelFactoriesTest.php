<?php

use App\Enums\EventStatus;
use App\Enums\SeatStatus;
use App\Enums\TierType;
use App\Models\Event;
use App\Models\Seat;
use App\Models\TicketTier;
use App\Models\User;

it('creates approved and pending organizers with the organizer state', function () {
    $approved = User::factory()->organizer()->create();
    $pending = User::factory()->organizer(approved: false)->create();

    $this->assertDatabaseHas('users', ['id' => $approved->id, 'role' => 'organizer', 'is_approved' => true]);
    $this->assertDatabaseHas('users', ['id' => $pending->id, 'role' => 'organizer', 'is_approved' => false]);
});

it('creates a draft event owned by an approved organizer', function () {
    $event = Event::factory()->create();

    expect($event->status)->toBe(EventStatus::Draft)
        ->and($event->end_date->greaterThan($event->event_date))->toBeTrue()
        ->and((bool) $event->organizer->is_approved)->toBeTrue();
});

it('supports the published and custom status states on events', function () {
    expect(Event::factory()->published()->create()->status)->toBe(EventStatus::Published)
        ->and(Event::factory()->withStatus(EventStatus::Suspended)->create()->status)->toBe(EventStatus::Suspended);
});

it('creates a seated tier with no seats by default', function () {
    $tier = TicketTier::factory()->create();

    expect($tier->type)->toBe(TierType::Seated)
        ->and($tier->event)->toBeInstanceOf(Event::class)
        ->and($tier->total_capacity)->toBe(0)
        ->and(Seat::where('ticket_tier_id', $tier->id)->count())->toBe(0);
});

it('creates a general admission tier with the given capacity', function () {
    $tier = TicketTier::factory()->generalAdmission(250)->create();

    expect($tier->type)->toBe(TierType::GeneralAdmission)
        ->and($tier->total_capacity)->toBe(250);
});

it('generates a seat grid and matching capacity with withSeats', function () {
    $tier = TicketTier::factory()->withSeats(rows: 2, perRow: 3)->create();
    $seats = Seat::where('ticket_tier_id', $tier->id)->orderBy('id')->get();

    expect($seats)->toHaveCount(6)
        ->and($seats->pluck('row_label')->unique()->values()->all())->toBe(['A', 'B'])
        ->and($seats->where('row_label', 'B')->pluck('seat_number')->values()->all())->toBe([1, 2, 3])
        ->and($seats->every(fn ($seat) => $seat->status === SeatStatus::Available))->toBeTrue()
        ->and($tier->fresh()->total_capacity)->toBe(6);
});

it('supports the held and booked states on seats', function () {
    expect(Seat::factory()->create()->status)->toBe(SeatStatus::Available)
        ->and(Seat::factory()->held()->create()->status)->toBe(SeatStatus::Held)
        ->and(Seat::factory()->booked()->create()->status)->toBe(SeatStatus::Booked);
});

it('creates several seats in one tier without unique violations', function () {
    $tier = TicketTier::factory()->create();

    Seat::factory()->count(5)->create(['ticket_tier_id' => $tier->id]);

    expect(Seat::where('ticket_tier_id', $tier->id)->count())->toBe(5);
});
