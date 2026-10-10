<?php

namespace App\Services;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

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
