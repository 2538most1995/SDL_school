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

    public function test_previous_terms_goes_backward_correctly(): void
    {
        // Going back 2 semesters from 1/2569 => 2/2568, 1/2568
        $result = AcademicTerm::previousTerms('1/2569', 2);
        $this->assertSame(['2/2568', '1/2568'], $result);
    }

    public function test_previous_terms_crosses_year_boundary(): void
    {
        // Going back 3 semesters from 2/2569 => 1/2569, 2/2568, 1/2568
        $result = AcademicTerm::previousTerms('2/2569', 3);
        $this->assertSame(['1/2569', '2/2568', '1/2568'], $result);
    }

    public function test_terms_in_range_returns_full_range_including_base(): void
    {
        // 10 semesters total (9 back + base) from 2/2569
        $result = AcademicTerm::termsInRange('2/2569', 9);
        $this->assertCount(10, $result);
        $this->assertSame('1/2565', $result[0]); // 4.5 years back
        $this->assertSame('2/2569', $result[9]); // current
    }

    public function test_terms_in_range_returns_empty_for_invalid_term(): void
    {
        $result = AcademicTerm::termsInRange('invalid', 9);
        $this->assertSame([], $result);
    }
}
