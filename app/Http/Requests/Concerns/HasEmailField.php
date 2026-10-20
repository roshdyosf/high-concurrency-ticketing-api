<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * @mixin FormRequest
 *
 * @phpstan-require-extends FormRequest
 */
trait HasEmailField
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => Str::lower(trim($email))]);
        }
    }

    /**
     * @return array<int, string>
     */
    protected function emailRules(): array
    {
        return ['required', 'string', 'email', 'max:255'];
    }
}
