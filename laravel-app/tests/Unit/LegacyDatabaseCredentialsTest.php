<?php

namespace Tests\Unit;

use App\Support\LegacyDatabaseCredentials;
use PHPUnit\Framework\TestCase;

final class LegacyDatabaseCredentialsTest extends TestCase
{
    public function test_it_reads_only_the_five_legacy_connection_defaults(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'sena-legacy-config-');
        self::assertNotFalse($path);

        file_put_contents($path, <<<'PHP'
<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: 3307;
$dbname = getenv('DB_NAME') ?: 'school';
$username = getenv('DB_USER') ?: 'reader';
$password = getenv('DB_PASS') ?: 'secret';
$pdo = new PDO('must-not-run');
PHP);

        try {
            self::assertSame([
                'host' => '127.0.0.1',
                'port' => 3307,
                'database' => 'school',
                'username' => 'reader',
                'password' => 'secret',
            ], LegacyDatabaseCredentials::fromLegacyConfig($path));
        } finally {
            @unlink($path);
        }
    }
}
