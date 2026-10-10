<?php

namespace App\Http\Controllers\Api\V1\Organizer;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organizer\BulkSeatsRequest;
use App\Http\Resources\TicketTierResource;
use App\Models\TicketTier;
use App\Services\OrganizerSeatService;
use Illuminate\Http\JsonResponse;

class SeatController extends Controller
{
    public function __construct(private readonly OrganizerSeatService $seats)
    {
    }

    public function bulk(BulkSeatsRequest $request, TicketTier $tier): JsonResponse
    {
        $tier = $this->seats->generate($tier, $request->integer('rows'), $request->integer('seats_per_row'));

        return (new TicketTierResource($tier))->response()->setStatusCode(201);
    }
}
