<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\User;

const CAT_URL = '/api/v1/events';

it('lists published events only', function () {
    $published = Event::factory()->published()->create();
    Event::factory()->create();
    Event::factory()->withStatus(EventStatus::Suspended)->create();
    Event::factory()->withStatus(EventStatus::Completed)->create();
    Event::factory()->withStatus(EventStatus::Cancelled)->create();

    $this->getJson(CAT_URL)
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $published->id);
});

it('is public and exposes only the agreed fields', function () {
    Event::factory()->published()->create();

    $this->getJson(CAT_URL)
        ->assertOk()
        ->assertJsonStructure([
            'data' => [['id', 'title', 'description', 'venue_name', 'location', 'event_date', 'end_date', 'image_url', 'organizer' => ['id', 'name']]],
            'links',
            'meta',
        ])
        ->assertJsonMissingPath('data.0.image_public_id')
        ->assertJsonMissingPath('data.0.status');
});

it('orders events by start date ascending', function () {
    $late = Event::factory()->published()->create(['event_date' => now()->addDays(30), 'end_date' => now()->addDays(30)->addHours(2)]);
    $soon = Event::factory()->published()->create(['event_date' => now()->addDays(10), 'end_date' => now()->addDays(10)->addHours(2)]);

    $this->getJson(CAT_URL)
        ->assertJsonPath('data.0.id', $soon->id)
        ->assertJsonPath('data.1.id', $late->id);
});

it('searches the title with full text', function () {
    $jazz = Event::factory()->published()->create(['title' => 'Jazz Night in Cairo']);
    Event::factory()->published()->create(['title' => 'Rock Festival']);

    $this->getJson(CAT_URL . '?search=jazz')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $jazz->id);
});

it('searches the description too', function () {
    $match = Event::factory()->published()->create(['title' => 'Evening', 'description' => 'An outdoor orchestra performance']);
    Event::factory()->published()->create(['title' => 'Morning', 'description' => 'A quiet workshop']);

    $this->getJson(CAT_URL . '?search=orchestra')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('filters by location with a case-insensitive partial match', function () {
    $cairo = Event::factory()->published()->create(['location' => 'New Cairo']);
    Event::factory()->published()->create(['location' => 'Alexandria']);

    $this->getJson(CAT_URL . '?location=cAiRo')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $cairo->id);
});

it('filters by organizer name', function () {
    $acme = User::factory()->organizer()->create(['name' => 'Acme Events']);
    $other = User::factory()->organizer()->create(['name' => 'Other Org']);
    $match = Event::factory()->published()->create(['organizer_id' => $acme->id]);
    Event::factory()->published()->create(['organizer_id' => $other->id]);

    $this->getJson(CAT_URL . '?organizer=acme')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id)
        ->assertJsonPath('data.0.organizer.name', 'Acme Events');
});

it('combines filters with and', function () {
    $match = Event::factory()->published()->create(['title' => 'Jazz Night', 'location' => 'Cairo']);
    Event::factory()->published()->create(['title' => 'Jazz Night', 'location' => 'Giza']);
    Event::factory()->published()->create(['title' => 'Rock Night', 'location' => 'Cairo']);

    $this->getJson(CAT_URL . '?search=jazz&location=cairo')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $match->id);
});

it('treats like wildcards in the location filter as literal characters', function () {
    Event::factory()->published()->create(['location' => 'Cairo']);
    Event::factory()->published()->create(['location' => 'Giza']);

    $this->getJson(CAT_URL . '?location=%25')->assertJsonCount(0, 'data');
});

it('paginates with per_page and reports the total', function () {
    Event::factory()->published()->count(3)->create();

    $this->getJson(CAT_URL . '?per_page=2')
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.per_page', 2);
});

it('rejects a per_page above the maximum', function () {
    $this->getJson(CAT_URL . '?per_page=51')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('per_page');
});

it('is limited to 120 requests per minute per ip', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->getJson(CAT_URL)->assertOk();
    }

    $this->getJson(CAT_URL)->assertStatus(429);
});
