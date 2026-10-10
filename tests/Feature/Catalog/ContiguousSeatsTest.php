<?php

use App\Enums\EventStatus;
use App\Enums\SeatStatus;
use App\Models\Event;
use App\Models\Seat;
use App\Models\TicketTier;

function cgSeats(TicketTier $tier, array $seats): void
{
    foreach ($seats as [$row, $number]) {
        Seat::factory()->create(['ticket_tier_id' => $tier->id, 'row_label' => $row, 'seat_number' => $number]);
    }
}

function cgUrl(Event $event, TicketTier $tier, string $query = 'count=2'): string
{
    return "/api/v1/events/{$event->id}/tiers/{$tier->id}/seats/contiguous?{$query}";
}

it('suggests the first seats of each run of consecutive seat numbers', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);
    cgSeats($tier, [['A', 1], ['A', 2], ['A', 3], ['A', 5], ['A', 6]]);

    $response = $this->getJson(cgUrl($event, $tier))->assertOk()->assertJsonPath('data.count', 2);

    $groups = $response->json('data.groups');

    expect($groups)->toHaveCount(2)
        ->and(collect($groups[0])->pluck('seat_number')->all())->toBe([1, 2])
        ->and(collect($groups[1])->pluck('seat_number')->all())->toBe([5, 6])
        ->and($groups[0][0])->toHaveKeys(['id', 'row_label', 'seat_number']);
});

it('skips held seats so a held seat breaks the run', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);
    cgSeats($tier, [['A', 1], ['A', 2], ['A', 3]]);
    Seat::where('ticket_tier_id', $tier->id)->where('seat_number', 2)->first()
        ->forceFill(['status' => SeatStatus::Held])->save();

    $this->getJson(cgUrl($event, $tier))->assertOk()->assertJsonPath('data.groups', []);
});

it('never joins seats across different rows', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);
    cgSeats($tier, [['A', 5], ['B', 6]]);

    $this->getJson(cgUrl($event, $tier))->assertOk()->assertJsonPath('data.groups', []);
});

it('returns at most three groups', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->create(['event_id' => $event->id]);
    cgSeats($tier, [['A', 1], ['A', 2], ['B', 1], ['B', 2], ['C', 1], ['C', 2], ['D', 1], ['D', 2]]);

    $this->getJson(cgUrl($event, $tier))->assertOk()->assertJsonCount(3, 'data.groups');
});

it('returns no groups for a general admission tier', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->generalAdmission(50)->create(['event_id' => $event->id]);

    $this->getJson(cgUrl($event, $tier))->assertOk()->assertJsonPath('data.groups', []);
});

it('rejects a count outside the allowed range', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 8)->create(['event_id' => $event->id]);

    foreach ([0, 7] as $count) {
        $this->getJson(cgUrl($event, $tier, "count={$count}"))
            ->assertUnprocessable()
            ->assertJsonPath('code', 'INVALID_SEAT_SELECTION');
    }
});

it('requires an integer count', function () {
    $event = Event::factory()->published()->create();
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 3)->create(['event_id' => $event->id]);

    $this->getJson(cgUrl($event, $tier, ''))->assertUnprocessable()->assertJsonValidationErrors('count');
    $this->getJson(cgUrl($event, $tier, 'count=abc'))->assertUnprocessable()->assertJsonValidationErrors('count');
});

it('hides an unpublished event as not found', function () {
    $event = Event::factory()->withStatus(EventStatus::Draft)->create();
    $tier = TicketTier::factory()->withSeats(rows: 1, perRow: 3)->create(['event_id' => $event->id]);

    $this->getJson(cgUrl($event, $tier))
        ->assertNotFound()
        ->assertJson(['code' => 'EVENT_NOT_FOUND']);
});

it('returns tier not found for an unknown tier or a tier of another event', function () {
    $event = Event::factory()->published()->create();
    $foreignTier = TicketTier::factory()->withSeats(rows: 1, perRow: 3)
        ->create(['event_id' => Event::factory()->published()->create()->id]);

    $this->getJson(cgUrl($event, $foreignTier))
        ->assertNotFound()
        ->assertJson(['code' => 'TIER_NOT_FOUND']);

    $this->getJson("/api/v1/events/{$event->id}/tiers/999999/seats/contiguous?count=2")
        ->assertNotFound()
        ->assertJson(['code' => 'TIER_NOT_FOUND']);
});

it('returns not found for non numeric ids', function () {
    $this->getJson('/api/v1/events/abc/tiers/1/seats/contiguous?count=2')->assertNotFound();
    $this->getJson('/api/v1/events/1/tiers/abc/seats/contiguous?count=2')->assertNotFound();
});
