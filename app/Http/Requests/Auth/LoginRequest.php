<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\HasEmailField;

class LoginRequest extends FormRequest
{
    use HasEmailField;
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }


    /**
     * @return array<string, array<int, mixed>>
     */

    public function rules(): array
    {
        return [
            'email' => $this->emailRules(),
            'password' => ['required', 'string'],
        ];
    }
}
