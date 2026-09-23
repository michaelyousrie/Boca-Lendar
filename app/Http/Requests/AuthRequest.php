<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuthRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->email)) {
            $this->merge(['email' => strtolower(trim($this->email))]);
        }
    }

    public function rules(): array
    {
        $register = $this->routeIs('register.store');

        return [
            'name' => $register ? ['required', 'string', 'max:100'] : ['exclude'],
            'email' => ['required', 'string', 'email:rfc', 'max:254', ...($register ? [Rule::unique('users')] : [])],
            'password' => ['required', 'string', ...($register ? ['min:10', 'confirmed'] : []),
                function (string $attribute, mixed $value, Closure $fail): void {
                    if (strlen($value) > 72) {
                        $fail('Use a password of at most 72 bytes.');
                    }
                },
            ],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
