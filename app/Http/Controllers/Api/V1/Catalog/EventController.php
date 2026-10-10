<?php

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Http\Controllers\Controller;
use App\Http\Requests\Catalog\ListEventsRequest;
use App\Http\Resources\EventResource;
use App\Services\EventCatalogService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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
}
