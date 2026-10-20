<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use App\Http\Requests\Concerns\HasEmailField;

class ResetPasswordRequest extends FormRequest
{
    use HasEmailField;
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
            'token' => ['required', 'string'],
            'email' => $this->emailRules(),
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
