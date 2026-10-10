<?php

namespace App\Http\Requests\Organizer;

use Illuminate\Foundation\Http\FormRequest;

class BulkSeatsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'rows' => ['required', 'integer', 'min:1', 'max:' . (int) config('ticketing.seat_bulk_max_rows')],
            'seats_per_row' => ['required', 'integer', 'min:1', 'max:' . (int) config('ticketing.seat_bulk_max_per_row')],
        ];
    }
}
