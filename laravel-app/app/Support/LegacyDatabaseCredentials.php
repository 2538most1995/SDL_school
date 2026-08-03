<?php

namespace App\Support;

use RuntimeException;

final class LegacyDatabaseCredentials
{
    /** @return array{host: ?string, port: int, database: ?string, username: ?string, password: ?string} */
    public static function resolve(): array
    {
        $credentials = [
            'host' => self::stringEnv('LEGACY_DB_HOST'),
            'port' => (int) (env('LEGACY_DB_PORT') ?: 3306),
            'database' => self::stringEnv('LEGACY_DB_DATABASE'),
            'username' => self::stringEnv('LEGACY_DB_USERNAME'),
            'password' => self::nullableEnv('LEGACY_DB_PASSWORD'),
        ];

        if ($credentials['host'] !== null
            && $credentials['database'] !== null
            && $credentials['username'] !== null
            && $credentials['password'] !== null) {
            return $credentials;
        }

        if (! filter_var(env('SENA_LEGACY_CONFIG_FALLBACK', false), FILTER_VALIDATE_BOOL)) {
            return $credentials;
        }

        $path = (string) (env('SENA_LEGACY_CONFIG_PATH') ?: storage_path('app/private/legacy-database.credentials'));

        try {
            return self::fromLegacyConfig($path);
        } catch (\Throwable) {
            return $credentials;
        }
    }

    /** @return array{host: string, port: int, database: string, username: string, password: string} */
    public static function fromLegacyConfig(string $path): array
    {
        $source = @file_get_contents($path);
        if ($source === false) {
            throw new RuntimeException('Legacy database configuration file is not readable.');
        }

        $values = [];
        foreach (['host', 'port', 'dbname', 'username', 'password'] as $variable) {
            $pattern = '/\$'.preg_quote($variable, '/').'\s*=\s*getenv\([^)]*\)\s*\?:\s*(?:([\'\"])(.*?)\1|([0-9]+))\s*;/s';
            if (preg_match($pattern, $source, $matches) !== 1) {
                throw new RuntimeException("Legacy database setting {$variable} is missing.");
            }
            $values[$variable] = isset($matches[3]) && $matches[3] !== ''
                ? $matches[3]
                : stripcslashes($matches[2]);
        }

        return [
            'host' => $values['host'],
            'port' => max(1, (int) $values['port']),
            'database' => $values['dbname'],
            'username' => $values['username'],
            'password' => $values['password'],
        ];
    }

    private static function stringEnv(string $key): ?string
    {
        $value = self::nullableEnv($key);

        return $value === null || trim($value) === '' ? null : trim($value);
    }

    private static function nullableEnv(string $key): ?string
    {
        $value = env($key);

        return $value === null ? null : (string) $value;
    }
}
