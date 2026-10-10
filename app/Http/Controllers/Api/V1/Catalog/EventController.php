<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Services\EventCatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use App\Http\Resources\SeatMapResource;
use App\Http\Requests\Catalog\ContiguousSeatsRequest;
use App\Http\Resources\ContiguousSeatsResource;

class EventController extends Controller
{
    public function __construct(private readonly EventCatalogService $catalog)
    {
    }

    public function index(ListEventsRequest $request): AnonymousResourceCollection
    {
        return EventResource::collection($this->catalog->list($request->validated()));
    }

    public function show(int $id): EventResource
    {
        return new EventResource($this->catalog->find($id));
    }

    public function seats(int $id): SeatMapResource
    {
        return new SeatMapResource($this->catalog->seatMap($id));
    }
    public function contiguous(ContiguousSeatsRequest $request, int $id, int $tierId): ContiguousSeatsResource
    {
        return new ContiguousSeatsResource(
            $this->catalog->contiguousSeats($id, $tierId, $request->integer('count'))
        );
    }
}
