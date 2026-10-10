<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use App\Exceptions\EventNotFoundException;
use App\Enums\TierType;
use App\Models\Seat;
use App\Models\TicketTier;

class EventCatalogService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Event>
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = Event::query()
            ->with('organizer:id,name')
            ->where('status', EventStatus::Published)
            ->orderBy('event_date')
            ->orderBy('id');

        $search = $this->text($filters, 'search');
        $location = $this->text($filters, 'location');
        $organizer = $this->text($filters, 'organizer');

        if ($search !== null) {
            $query->whereRaw(
                "to_tsvector('simple', coalesce(title, '') || ' ' || coalesce(description, '')) @@ plainto_tsquery('simple', ?)",
                [$search],
            );
        }

        if ($location !== null) {
            $query->where('location', 'ilike', $this->like($location));
        }

        if ($organizer !== null) {
            $query->whereHas('organizer', fn (Builder $user) => $user->where('name', 'ilike', $this->like($organizer)));
        }

        $perPage = is_numeric($filters['per_page'] ?? null) ? (int) $filters['per_page'] : 15;

        return $query->paginate($perPage)->withQueryString();
    }

    public function find(int $id): Event
    {
        $event = Event::query()
            ->with([
                'organizer:id,name',
                'ticketTiers' => fn ($tiers) => $tiers->orderBy('id'),
            ])
            ->where('status', EventStatus::Published)
            ->find($id);

        return $event ?? throw new EventNotFoundException();
    }

    /**
     * @return array{event_id: int, tiers: list<array<string, mixed>>}
     */
    public function seatMap(int $id): array
    {
        $event = Event::query()
            ->where('status', EventStatus::Published)
            ->find($id) ?? throw new EventNotFoundException();

        $tiers = $event->ticketTiers()
            ->where('type', TierType::Seated)
            ->orderBy('id')
            ->get();

        $seatsByTier = Seat::query()
            ->whereIn('ticket_tier_id', $tiers->pluck('id'))
            ->orderByRaw('length(row_label), row_label')
            ->orderBy('seat_number')
            ->get()
            ->groupBy('ticket_tier_id');

        return [
            'event_id' => $event->id,
            'tiers' => $tiers->map(fn (TicketTier $tier): array => [
                'id' => $tier->id,
                'name' => $tier->name,
                'price' => $tier->price,
                'rows' => $seatsByTier->get($tier->id, collect())
                    ->groupBy('row_label')
                    ->map(fn ($rowSeats, $label): array => [
                        'row_label' => (string) $label,
                        'seats' => $rowSeats->map(fn (Seat $seat): array => [
                            'id' => $seat->id,
                            'seat_number' => $seat->seat_number,
                            'status' => $seat->status->value,
                        ])->values()->all(),
                    ])
                    ->values()
                    ->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */

    private function text(array $filters, string $key): ?string
    {
        $value = $filters[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private function like(string $value): string
    {
        return '%' . addcslashes($value, '%_\\') . '%';
    }
}
