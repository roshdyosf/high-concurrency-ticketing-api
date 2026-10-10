<?php

use App\Enums\EventStatus;
use App\Models\Event;
use App\Models\TicketTier;
use App\Models\User;
use Illuminate\Support\Facades\Redis;

/**
 * @return array{0: User, 1: array<string, string>}
 */
function ctrOrganizer(): array
{
    $user = User::factory()->organizer()->create();
    $user->forceFill(['is_approved' => true])->save();

    return [$user, ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken]];
}

function ctrUrl(Event $event): string
{
    return "/api/v1/organizer/events/{$event->id}/tiers";
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function ctrGa(array $overrides = []): array
{
    return array_merge(['name' => 'Floor', 'type' => 'general_admission', 'price' => 25, 'total_capacity' => 50], $overrides);
}

it('creates a seated tier with zero capacity and no redis counter', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    $response = $this->postJson(ctrUrl($event), ['name' => 'VIP', 'type' => 'seated', 'price' => 80.5], $headers)
        ->assertCreated()
        ->assertJsonPath('data.name', 'VIP')
        ->assertJsonPath('data.type', 'seated')
        ->assertJsonPath('data.price', '80.50')
        ->assertJsonPath('data.total_capacity', 0);

    $tier = TicketTier::findOrFail($response->json('data.id'));

    expect($tier->event_id)->toBe($event->id)
        ->and(Redis::exists("tier_capacity:{$tier->id}"))->toBe(0);
});

it('creates a general admission tier and initialises its redis counter', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    $response = $this->postJson(ctrUrl($event), ctrGa(), $headers)
        ->assertCreated()
        ->assertJsonPath('data.type', 'general_admission')
        ->assertJsonPath('data.total_capacity', 50);

    expect((int) Redis::get('tier_capacity:' . $response->json('data.id')))->toBe(50);
});

it('requires total_capacity for a general admission tier', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    $this->postJson(ctrUrl($event), ctrGa(['total_capacity' => null]), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('total_capacity');
});

it('rejects total_capacity on a seated tier', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    $this->postJson(ctrUrl($event), ['name' => 'VIP', 'type' => 'seated', 'price' => 50, 'total_capacity' => 10], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('total_capacity');
});

it('validates name type and price', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    $this->postJson(ctrUrl($event), [], $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'type', 'price']);

    $this->postJson(ctrUrl($event), ctrGa(['type' => 'balcony']), $headers)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('type');
});

it('rejects a price below the minimum charge or with more than two decimals', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->create(['organizer_id' => $user->id]);

    foreach ([0, 0.1, 10.999] as $price) {
        $this->postJson(ctrUrl($event), ctrGa(['price' => $price]), $headers)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('price');
    }

    expect(TicketTier::count())->toBe(0);
});

it('allows adding a tier to a published event', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->published()->create(['organizer_id' => $user->id]);

    $this->postJson(ctrUrl($event), ctrGa(), $headers)->assertCreated();
});

it('rejects suspended, completed and cancelled events with EVENT_NOT_EDITABLE', function () {
    [$user, $headers] = ctrOrganizer();

    foreach ([EventStatus::Suspended, EventStatus::Completed, EventStatus::Cancelled] as $status) {
        $event = Event::factory()->withStatus($status)->create(['organizer_id' => $user->id]);

        $this->postJson(ctrUrl($event), ctrGa(), $headers)
            ->assertStatus(409)
            ->assertJsonPath('code', 'EVENT_NOT_EDITABLE');
    }

    expect(TicketTier::count())->toBe(0);
});

it('forbids adding a tier to another organizers event', function () {
    [, $headers] = ctrOrganizer();
    $event = Event::factory()->create();

    $this->postJson(ctrUrl($event), ctrGa(), $headers)->assertForbidden();

    expect(TicketTier::count())->toBe(0);
});

it('returns not found for an unknown or non numeric event id', function () {
    [, $headers] = ctrOrganizer();

    $this->postJson('/api/v1/organizer/events/999999/tiers', ctrGa(), $headers)->assertNotFound();
    $this->postJson('/api/v1/organizer/events/abc/tiers', ctrGa(), $headers)->assertNotFound();
});

it('rejects a request without a token', function () {
    $event = Event::factory()->create();

    $this->postJson(ctrUrl($event), ctrGa())->assertUnauthorized();
});

it('shows the new tier in the public event details once published', function () {
    [$user, $headers] = ctrOrganizer();
    $event = Event::factory()->published()->create(['organizer_id' => $user->id]);

    $this->postJson(ctrUrl($event), ctrGa(), $headers)->assertCreated();

    $this->getJson("/api/v1/events/{$event->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data.tiers')
        ->assertJsonPath('data.tiers.0.name', 'Floor');
});
