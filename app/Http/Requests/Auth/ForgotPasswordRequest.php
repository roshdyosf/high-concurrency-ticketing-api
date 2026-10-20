<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use App\Http\Requests\Concerns\HasEmailField;

class ForgotPasswordRequest extends FormRequest
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
            'email' => $this->emailRules(),
        ];
    }
}
