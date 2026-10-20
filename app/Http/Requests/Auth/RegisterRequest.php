<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;
use App\Http\Requests\Concerns\HasEmailField;

class RegisterRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'email' => [...$this->emailRules(), 'unique:users,email'],
            'password' => ['required', 'string', Password::defaults()],
        ];
    }
}
