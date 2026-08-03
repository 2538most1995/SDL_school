<?php

namespace App\Domain\Learning;

use Closure;

final class DemoQueryRules
{
    /**
     * @return list<mixed>
     */
    public static function search(): array
    {
        return [
            'nullable',
            'string',
            'max:80',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || preg_match('//u', $value) !== 1) {
                    $fail("The {$attribute} field must contain valid UTF-8 text.");
                }
            },
        ];
    }
}
