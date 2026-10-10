<?php

namespace App\Http\Requests\Organizer;

use App\Enums\TierType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateTierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $minCharge = (string) config('ticketing.currencies.' . config('ticketing.currency') . '.min_charge');

        return [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(TierType::class)],
            'price' => ['required', 'numeric', 'decimal:0,2', 'min:' . $minCharge, 'max:99999999.99'],
            'total_capacity' => ['required_if:type,general_admission', 'prohibited_if:type,seated', 'integer', 'min:1', 'max:100000'],
        ];
    }
}
