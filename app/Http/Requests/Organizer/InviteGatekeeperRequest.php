<?php

namespace App\Http\Requests\Organizer;

use App\Http\Requests\Concerns\HasEmailField;
use Illuminate\Foundation\Http\FormRequest;

class InviteGatekeeperRequest extends FormRequest
{
    use HasEmailField;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
        ];
    }
}
