<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketTier;
use App\Models\User;

it('returns a published event with its tiers ordered by id', function () {
    $event = Event::factory()->published()->create();
    $first = TicketTier::factory()->create(['event_id' => $event->id, 'name' => 'VIP']);
    $second = TicketTier::factory()->generalAdmission(50)->create(['event_id' => $event->id, 'name' => 'Floor']);

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $event->id)
        ->assertJsonCount(2, 'data.tiers')
        ->assertJsonPath('data.tiers.0.id', $first->id)
        ->assertJsonPath('data.tiers.1.id', $second->id);
});

it('exposes the agreed tier fields', function () {
    $event = Event::factory()->published()->create();
    TicketTier::factory()->generalAdmission(50)->create(['event_id' => $event->id, 'price' => 25]);
    TicketTier::factory()->withSeats(rows: 1, perRow: 3)->create(['event_id' => $event->id, 'price' => 80.5]);

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.tiers.0.type', 'general_admission')
        ->assertJsonPath('data.tiers.0.price', '25.00')
        ->assertJsonPath('data.tiers.0.total_capacity', 50)
        ->assertJsonPath('data.tiers.1.type', 'seated')
        ->assertJsonPath('data.tiers.1.price', '80.50')
        ->assertJsonPath('data.tiers.1.total_capacity', 3);
});

it('returns an empty tiers list for an event without tiers', function () {
    $event = Event::factory()->published()->create();

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.tiers', []);
});

it('is public and shows the organizer without internal fields', function () {
    $organizer = User::factory()->organizer()->create(['name' => 'Acme Events']);
    $event = Event::factory()->published()->create(['organizer_id' => $organizer->id]);

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonPath('data.organizer.name', 'Acme Events')
        ->assertJsonMissingPath('data.image_public_id')
        ->assertJsonMissingPath('data.status');
});

it('hides a draft event as not found', function () {
    $event = Event::factory()->create();

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertNotFound()
        ->assertJson(['code' => 'EVENT_NOT_FOUND']);
});

it('hides suspended, completed and cancelled events as not found', function () {
    foreach ([EventStatus::Suspended, EventStatus::Completed, EventStatus::Cancelled] as $status) {
        $event = Event::factory()->withStatus($status)->create();

        $this->getJson("/api/v1/events/{$event->id}")
            ->assertNotFound()
            ->assertJson(['code' => 'EVENT_NOT_FOUND']);
    }
});

it('returns not found for an unknown id', function () {
    $this->getJson('/api/v1/events/999999')
        ->assertNotFound()
        ->assertJson(['code' => 'EVENT_NOT_FOUND']);
});

it('returns not found for a non numeric id', function () {
    $this->getJson('/api/v1/events/abc')->assertNotFound();
});
