<?php

namespace Tests\Unit;

use App\Services\Legacy\LegacyZipImportService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class LegacyImportReplacementTest extends TestCase
{
    private string $workspace;

    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/sena-import-replacement-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->workspace, 0750, true);
        File::makeDirectory($this->workspace.'/zips', 0750, true);
        File::makeDirectory($this->workspace.'/extracted', 0750, true);
        $this->databasePath = $this->workspace.'/system.sqlite';
        touch($this->databasePath);

        config()->set('database.connections.replacement_test', [
            'driver' => 'sqlite',
            'database' => $this->databasePath,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'replacement_test');
        config()->set('system_data.zip_root', $this->workspace.'/zips');
        config()->set('system_data.extract_root', $this->workspace.'/extracted');
        DB::purge('replacement_test');

        Schema::connection('replacement_test')->create('import_history', function ($table): void {
            $table->id();
            $table->string('file_name');
            $table->string('saved_file_name');
            $table->string('batch_key')->nullable();
            $table->unsignedBigInteger('district_id')->nullable();
            $table->string('status')->nullable();
        });
        Schema::connection('replacement_test')->create('import_batches', function ($table): void {
            $table->string('batch_key')->primary();
            $table->unsignedBigInteger('district_id');
            $table->unsignedBigInteger('import_history_id')->nullable();
            $table->timestamp('created_at')->nullable();
        });
    }

    protected function tearDown(): void
    {
        DB::purge('replacement_test');
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_new_batch_replaces_only_old_batches_from_same_district(): void
    {
        $old = 'import_1700000000_aaaa';
        $active = 'import_1700000001_bbbb';
        $otherDistrict = 'import_1700000002_cccc';
        $connection = DB::connection('replacement_test');

        foreach ([[$old, 1], [$active, 1], [$otherDistrict, 2]] as [$batch, $district]) {
            $historyId = $connection->table('import_history')->insertGetId([
                'file_name' => $batch.'.zip',
                'saved_file_name' => $batch.'.zip',
                'batch_key' => $batch,
                'district_id' => $district,
                'status' => 'success',
            ]);
            $connection->table('import_batches')->insert([
                'batch_key' => $batch,
                'district_id' => $district,
                'import_history_id' => $historyId,
                'created_at' => now(),
            ]);
            Schema::connection('replacement_test')->create('db_'.$batch.'_1_student', fn ($table) => $table->id());
            File::put($this->workspace.'/zips/'.$batch.'.zip', 'zip');
            File::makeDirectory($this->workspace.'/extracted/'.$batch, 0750, true);
            File::put($this->workspace.'/extracted/'.$batch.'/student.dbf', 'dbf');
            File::put($this->workspace.'/extracted/'.$batch.'/student.fpt', 'memo');
        }

        $cleanup = app(LegacyZipImportService::class)->replaceExistingDistrictBatches(1, $active);

        $this->assertSame(1, $cleanup['removed_count']);
        $this->assertSame([$old], $cleanup['removed_batch_keys']);
        $this->assertSame([], $cleanup['warnings']);
        $this->assertDatabaseMissing('import_batches', ['batch_key' => $old], 'replacement_test');
        $this->assertDatabaseHas('import_batches', ['batch_key' => $active, 'district_id' => 1], 'replacement_test');
        $this->assertDatabaseHas('import_batches', ['batch_key' => $otherDistrict, 'district_id' => 2], 'replacement_test');
        $this->assertFalse(Schema::connection('replacement_test')->hasTable('db_'.$old.'_1_student'));
        $this->assertTrue(Schema::connection('replacement_test')->hasTable('db_'.$active.'_1_student'));
        $this->assertTrue(Schema::connection('replacement_test')->hasTable('db_'.$otherDistrict.'_1_student'));
        $this->assertFileDoesNotExist($this->workspace.'/zips/'.$old.'.zip');
        $this->assertDirectoryDoesNotExist($this->workspace.'/extracted/'.$old);
        $this->assertFileExists($this->workspace.'/extracted/'.$active.'/student.fpt');
        $this->assertFileExists($this->workspace.'/zips/'.$otherDistrict.'.zip');
    }

    public function test_cleanup_refuses_to_run_when_active_batch_is_not_owned_by_district(): void
    {
        $old = 'import_1700000010_aaaa';
        $otherDistrict = 'import_1700000011_bbbb';
        $connection = DB::connection('replacement_test');
        foreach ([[$old, 1], [$otherDistrict, 2]] as [$batch, $district]) {
            $historyId = $connection->table('import_history')->insertGetId([
                'file_name' => $batch.'.zip',
                'saved_file_name' => $batch.'.zip',
                'batch_key' => $batch,
                'district_id' => $district,
                'status' => 'success',
            ]);
            $connection->table('import_batches')->insert([
                'batch_key' => $batch,
                'district_id' => $district,
                'import_history_id' => $historyId,
                'created_at' => now(),
            ]);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ไม่พบ batch ใหม่ในทะเบียนของอำเภอ');

        try {
            app(LegacyZipImportService::class)->replaceExistingDistrictBatches(1, $otherDistrict);
        } finally {
            $this->assertDatabaseHas('import_batches', ['batch_key' => $old, 'district_id' => 1], 'replacement_test');
        }
    }

    public function test_unregistered_staging_batch_can_be_removed_without_touching_registered_data(): void
    {
        $registered = 'import_1700000020_aaaa';
        $orphan = 'import_1700000021_bbbb';
        $connection = DB::connection('replacement_test');
        $historyId = $connection->table('import_history')->insertGetId([
            'file_name' => $registered.'.zip',
            'saved_file_name' => $registered.'.zip',
            'batch_key' => $registered,
            'district_id' => 1,
            'status' => 'success',
        ]);
        $connection->table('import_batches')->insert([
            'batch_key' => $registered,
            'district_id' => 1,
            'import_history_id' => $historyId,
            'created_at' => now(),
        ]);
        foreach ([$registered, $orphan] as $batch) {
            Schema::connection('replacement_test')->create('db_'.$batch.'_1_student', fn ($table) => $table->id());
            File::put($this->workspace.'/zips/'.$batch.'.zip', 'zip');
            File::makeDirectory($this->workspace.'/extracted/'.$batch, 0750, true);
            File::put($this->workspace.'/extracted/'.$batch.'/student.dbf', 'dbf');
        }

        $result = app(LegacyZipImportService::class)->removeUnregisteredStagingBatch($orphan);

        $this->assertSame(1, $result['removed_table_count']);
        $this->assertTrue($result['removed_zip']);
        $this->assertTrue($result['removed_extract_directory']);
        $this->assertFalse(Schema::connection('replacement_test')->hasTable('db_'.$orphan.'_1_student'));
        $this->assertTrue(Schema::connection('replacement_test')->hasTable('db_'.$registered.'_1_student'));
        $this->assertFileExists($this->workspace.'/zips/'.$registered.'.zip');
    }

    public function test_registered_batch_delete_is_limited_to_its_district_and_removes_all_files(): void
    {
        $target = 'import_1700000030_aaaa';
        $otherDistrict = 'import_1700000031_bbbb';
        $connection = DB::connection('replacement_test');
        foreach ([[$target, 1], [$otherDistrict, 2]] as [$batch, $district]) {
            $historyId = $connection->table('import_history')->insertGetId([
                'file_name' => $batch.'.zip',
                'saved_file_name' => $batch.'.zip',
                'batch_key' => $batch,
                'district_id' => $district,
                'status' => 'success',
            ]);
            $connection->table('import_batches')->insert([
                'batch_key' => $batch,
                'district_id' => $district,
                'import_history_id' => $historyId,
                'created_at' => now(),
            ]);
            Schema::connection('replacement_test')->create('db_'.$batch.'_1_student', fn ($table) => $table->id());
            File::put($this->workspace.'/zips/'.$batch.'.zip', 'zip');
            File::makeDirectory($this->workspace.'/extracted/'.$batch.'/1', 0750, true);
            File::put($this->workspace.'/extracted/'.$batch.'/1/student.dbf', 'dbf');
            File::put($this->workspace.'/extracted/'.$batch.'/1/student.fpt', 'memo');
        }

        $result = app(LegacyZipImportService::class)->deleteDistrictBatch(1, $target);

        $this->assertSame($target, $result['batch_key']);
        $this->assertSame(1, $result['removed_table_count']);
        $this->assertTrue($result['removed_zip']);
        $this->assertTrue($result['removed_extract_directory']);
        $this->assertDatabaseMissing('import_batches', ['batch_key' => $target], 'replacement_test');
        $this->assertDatabaseMissing('import_history', ['batch_key' => $target], 'replacement_test');
        $this->assertFalse(Schema::connection('replacement_test')->hasTable('db_'.$target.'_1_student'));
        $this->assertFileDoesNotExist($this->workspace.'/zips/'.$target.'.zip');
        $this->assertDirectoryDoesNotExist($this->workspace.'/extracted/'.$target);

        $this->assertDatabaseHas('import_batches', ['batch_key' => $otherDistrict, 'district_id' => 2], 'replacement_test');
        $this->assertTrue(Schema::connection('replacement_test')->hasTable('db_'.$otherDistrict.'_1_student'));
        $this->assertFileExists($this->workspace.'/zips/'.$otherDistrict.'.zip');
        $this->assertFileExists($this->workspace.'/extracted/'.$otherDistrict.'/1/student.fpt');
    }

    public function test_registered_batch_delete_refuses_a_batch_from_another_district(): void
    {
        $batch = 'import_1700000040_aaaa';
        $connection = DB::connection('replacement_test');
        $historyId = $connection->table('import_history')->insertGetId([
            'file_name' => $batch.'.zip',
            'saved_file_name' => $batch.'.zip',
            'batch_key' => $batch,
            'district_id' => 2,
            'status' => 'success',
        ]);
        $connection->table('import_batches')->insert([
            'batch_key' => $batch,
            'district_id' => 2,
            'import_history_id' => $historyId,
            'created_at' => now(),
        ]);
        Schema::connection('replacement_test')->create('db_'.$batch.'_1_student', fn ($table) => $table->id());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ไม่พบชุดข้อมูลในอำเภอที่เลือก');

        try {
            app(LegacyZipImportService::class)->deleteDistrictBatch(1, $batch);
        } finally {
            $this->assertDatabaseHas('import_batches', ['batch_key' => $batch, 'district_id' => 2], 'replacement_test');
            $this->assertTrue(Schema::connection('replacement_test')->hasTable('db_'.$batch.'_1_student'));
        }
    }
}
