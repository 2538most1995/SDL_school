<?php

namespace Tests\Feature\Students;

use App\Models\District;
use App\Models\User;
use Database\Seeders\StudentDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

final class StudentDemoSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_scoped_identities_only_in_an_empty_non_production_database(): void
    {
        $this->seed(StudentDemoSeeder::class);

        $this->assertDatabaseCount('districts', 1);
        $this->assertDatabaseCount('users', 3);
        $this->assertSame(
            ['admin', 'student', 'teacher'],
            User::query()->orderBy('role')->pluck('role')->all(),
        );
        $this->assertSame('demo-sena', District::query()->value('code'));
    }

    public function test_demo_seeder_refuses_to_modify_a_non_empty_database(): void
    {
        District::query()->create([
            'name' => 'ข้อมูลเดิม',
            'code' => 'existing',
            'is_active' => true,
        ]);

        $this->expectException(LogicException::class);
        $this->seed(StudentDemoSeeder::class);
    }
}
