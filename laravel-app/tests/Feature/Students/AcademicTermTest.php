<?php

namespace Tests\Feature\Students;

use App\Domain\Students\Support\AcademicTerm;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AcademicTermTest extends TestCase
{
    #[DataProvider('legacyTerms')]
    public function test_it_normalizes_legacy_academic_term_formats(string $raw, string $canonical): void
    {
        $this->assertSame($canonical, AcademicTerm::normalize($raw));
    }

    /** @return iterable<string, array{string, string}> */
    public static function legacyTerms(): iterable
    {
        yield 'short year first' => ['68/2', '2/2568'];
        yield 'short semester first' => ['2/68', '2/2568'];
        yield 'buddhist year first' => ['2568/2', '2/2568'];
        yield 'canonical' => ['2/2568', '2/2568'];
        yield 'christian year first' => ['2025/2', '2/2568'];
        yield 'thai digits' => ['๒/๒๕๖๘', '2/2568'];
    }

    public function test_it_returns_variants_and_selects_the_latest_term(): void
    {
        $this->assertContains('68/2', AcademicTerm::variants('2/2568'));
        $this->assertContains('2568/2', AcademicTerm::variants('2/2568'));
        $this->assertSame('1/2569', AcademicTerm::latest(['68/2', '1/2569', '2/2568']));
        $this->assertNull(AcademicTerm::normalize('not-a-term'));
    }
}
