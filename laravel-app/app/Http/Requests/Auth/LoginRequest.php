<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'identifier' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:200'],
            'login_type' => ['sometimes', 'string', 'in:staff,student'],
            'district_id' => ['nullable', 'integer', Rule::exists('districts', 'id')->where('is_active', true)],
            'remember' => ['sometimes', 'boolean'],
        ];
    }
}
