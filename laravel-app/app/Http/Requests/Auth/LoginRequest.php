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
            'password' => [
                Rule::requiredIf(fn (): bool => ! $this->isSystemStudentLogin()),
                'nullable',
                'string',
                'max:200',
            ],
            'login_type' => ['sometimes', 'string', 'in:staff,student'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    private function isSystemStudentLogin(): bool
    {
        return $this->string('login_type')->toString() === 'student'
            && (bool) config('system_data.student_enabled');
    }
}
