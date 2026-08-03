<?php

namespace Tests\Feature;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LegacyRouteCompatibilityTest extends TestCase
{
    #[DataProvider('legacyRoutes')]
    public function test_legacy_page_redirects_to_laravel_route(string $legacy, string $target): void
    {
        $this->get('/'.$legacy)->assertRedirect($target);
    }

    public static function legacyRoutes(): array
    {
        return [
            ['login.php', '/login'],
            ['students.php', '/students'],
            ['grades.php', '/grades'],
            ['assignments.php', '/learning/assignments'],
            ['import.php', '/admin/imports'],
            ['theme.php', '/settings/appearance'],
        ];
    }
}
