<?php

namespace Tests\Unit;

use App\Services\Legacy\LegacyZipImportService;
use App\Support\VisualFoxProDbfReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

final class LegacyZipImportSafetyTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspace = sys_get_temp_dir().'/sena-import-safety-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->workspace, 0750, true);
        config()->set('system_data.write_enabled', true);
        config()->set('system_data.zip_root', $this->workspace.'/zips');
        config()->set('system_data.extract_root', $this->workspace.'/extracted');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);
        parent::tearDown();
    }

    public function test_import_rejects_zip_slip_without_writing_outside_staging(): void
    {
        $source = $this->workspace.'/malicious.zip';
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($source, ZipArchive::CREATE) === true);
        $zip->addFromString('../escaped.dbf', 'malicious');
        $zip->close();
        $upload = new UploadedFile($source, 'malicious.zip', 'application/zip', null, true);

        try {
            app(LegacyZipImportService::class)->import($upload, '1/2569', 1, 1, '127.0.0.1');
            $this->fail('Expected unsafe ZIP path to be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('path ไม่ปลอดภัย', $exception->getMessage());
        }

        $this->assertFileDoesNotExist($this->workspace.'/escaped.dbf');
        $this->assertCount(0, File::allFiles($this->workspace.'/extracted'));
    }

    public function test_import_selects_only_tables_used_by_laravel_portal(): void
    {
        $extract = $this->workspace.'/candidate-filter';
        File::makeDirectory($extract.'/1', 0750, true);
        foreach (['student', 'grade', 'subject', 'activity', 'virtue', 'group', 'schedule', 'receipt', 'student2'] as $type) {
            File::put("{$extract}/1/{$type}.dbf", 'placeholder');
        }

        $method = new \ReflectionMethod(LegacyZipImportService::class, 'dbfCandidates');
        $candidates = $method->invoke(app(LegacyZipImportService::class), $extract);

        $this->assertSame(
            ['activity', 'grade', 'group', 'schedule', 'student', 'subject', 'virtue'],
            collect($candidates)->pluck('type')->sort()->values()->all(),
        );
    }

    public function test_student_duplicates_are_selected_before_mysql_insert(): void
    {
        $path = $this->workspace.'/student.dbf';
        $fields = [
            ['name' => 'id', 'type' => 'C', 'length' => 10],
            ['name' => 'cardid', 'type' => 'C', 'length' => 13],
        ];
        $records = [
            ['6650000001', '1111111111111'],
            ['6750000001', '1111111111111'],
            ['6750000001', '1111111111111'],
            ['6850000002', ''],
        ];
        File::put($path, $this->dbf($fields, $records));

        $method = new \ReflectionMethod(LegacyZipImportService::class, 'selectedStudentRowIndexes');
        $selected = $method->invoke(app(LegacyZipImportService::class), new VisualFoxProDbfReader($path));

        $this->assertSame([2 => true], $selected);
        $this->assertArrayNotHasKey(3, $selected, 'Rows without a citizen ID remain importable outside the duplicate map.');
    }

    public function test_student_dbf_with_memo_fields_requires_matching_fpt_file(): void
    {
        $extract = $this->workspace.'/memo-companion';
        File::makeDirectory($extract.'/2', 0750, true);
        $studentPath = $extract.'/2/STUDENT.DBF';
        File::put($studentPath, $this->dbf(
            [
                ['name' => 'id', 'type' => 'C', 'length' => 10],
                ['name' => 'curphone', 'type' => 'M', 'length' => 4],
            ],
            [['6722000227', "\0\0\0\0"]],
        ));
        $candidates = [[
            'parent' => '2',
            'type' => 'student',
            'path' => $studentPath,
            'mtime' => filemtime($studentPath),
        ]];
        $method = new \ReflectionMethod(LegacyZipImportService::class, 'assertStudentMemoCompanions');

        try {
            $method->invoke(app(LegacyZipImportService::class), $candidates);
            $this->fail('Expected a missing FPT companion to be rejected.');
        } catch (\ReflectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('STUDENT.FPT', $exception->getMessage());
        }

        File::put($extract.'/2/student.FPT', 'memo');
        $method->invoke(app(LegacyZipImportService::class), $candidates);
        $this->addToAssertionCount(1);
    }

    public function test_dbf_rows_are_imported_in_batches_without_losing_records(): void
    {
        $database = $this->workspace.'/batch-import.sqlite';
        touch($database);
        config()->set('database.connections.batch_import_test', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        config()->set('database.default', 'batch_import_test');
        DB::purge('batch_import_test');

        $path = $this->workspace.'/grade.dbf';
        $records = [];
        for ($index = 1; $index <= 1_050; $index++) {
            $records[] = [str_pad((string) $index, 10, '0', STR_PAD_LEFT), '2/2568', 'ทช'.str_pad((string) $index, 5, '0', STR_PAD_LEFT)];
        }
        File::put($path, $this->dbf(
            [
                ['name' => 'std_code', 'type' => 'C', 'length' => 10],
                ['name' => 'semestry', 'type' => 'C', 'length' => 10],
                ['name' => 'sub_code', 'type' => 'C', 'length' => 8],
            ],
            $records,
        ));

        try {
            $method = new \ReflectionMethod(LegacyZipImportService::class, 'importDbf');
            $progress = [];
            $report = $method->invoke(
                app(LegacyZipImportService::class),
                'import_1700000099_abcd',
                '1',
                'grade',
                $path,
                static function (int $processed, int $total) use (&$progress): void {
                    $progress[] = [$processed, $total];
                },
            );

            $this->assertSame(1_050, $report['row_count']);
            $this->assertSame(1_050, DB::connection('batch_import_test')->table($report['physical_table'])->count());
            $this->assertSame('0000001050', DB::connection('batch_import_test')->table($report['physical_table'])->max('_perf_std10'));
            $this->assertSame([1_050, 1_050], end($progress));
            $indexes = DB::connection('batch_import_test')->getSchemaBuilder()->getIndexes($report['physical_table']);
            $this->assertContains(
                ['_perf_std10', '_perf_semestry', '_perf_sub'],
                array_map(static fn (array $index): array => $index['columns'], $indexes),
            );
        } finally {
            DB::purge('batch_import_test');
        }
    }

    public function test_import_preserves_foxpro_null_scores_even_when_old_numbers_remain_in_the_record(): void
    {
        $database = $this->workspace.'/nullable-import.sqlite';
        touch($database);
        config()->set('database.connections.nullable_import_test', [
            'driver' => 'sqlite',
            'database' => $database,
            'prefix' => '',
        ]);
        config()->set('database.default', 'nullable_import_test');
        DB::purge('nullable_import_test');

        $path = $this->workspace.'/nullable-grade.dbf';
        File::put($path, $this->dbf([
            ['name' => 'std_code', 'type' => 'C', 'length' => 20],
            ['name' => 'semestry', 'type' => 'C', 'length' => 4],
            ['name' => 'sub_code', 'type' => 'C', 'length' => 8],
            ['name' => 'midterm', 'type' => 'N', 'length' => 3, 'flags' => 2],
            ['name' => 'final', 'type' => 'N', 'length' => 3, 'flags' => 2],
            ['name' => 'total', 'type' => 'N', 'length' => 3],
            ['name' => 'grade', 'type' => 'C', 'length' => 3],
            ['name' => 'final1', 'type' => 'N', 'length' => 3, 'flags' => 2],
            ['name' => 'final2', 'type' => 'N', 'length' => 3, 'flags' => 2],
            ['name' => '_NullFlags', 'type' => '0', 'length' => 1, 'flags' => 5],
        ], [
            ['12141200006913000001', '69/1', 'TEST01', '50', '32', '50', 'X', '19', '32', "\x0e"],
            ['12141200006913000002', '69/1', 'TEST01', '50', '32', '82', '4', '19', '32', "\x00"],
            ['12141200006913000003', '69/1', 'TEST01', '0', '0', '0', '0', '0', '0', "\x00"],
        ]));

        try {
            $report = (new \ReflectionMethod(LegacyZipImportService::class, 'importDbf'))->invoke(
                app(LegacyZipImportService::class), 'import_1700000098_abcd', '3', 'grade', $path,
            );
            $rows = DB::connection('nullable_import_test')->table($report['physical_table'])->orderBy('_id')->get();
            $this->assertSame(3, $report['row_count']);
            $this->assertSame('6913000001', $rows[0]->_perf_std10);
            $this->assertSame('50', $rows[0]->midterm);
            $this->assertNull($rows[0]->final);
            $this->assertNull($rows[0]->final1);
            $this->assertNull($rows[0]->final2);
            $this->assertSame('50', $rows[0]->total);
            $this->assertSame('X', $rows[0]->grade);
            $this->assertSame('0e', $rows[0]->_nullflags);
            $this->assertSame('32', $rows[1]->final);
            $this->assertSame('82', $rows[1]->total);
            $this->assertSame('0', $rows[2]->midterm);
            $this->assertSame('0', $rows[2]->final);
        } finally {
            DB::purge('nullable_import_test');
        }
    }

    public function test_null_bitmap_uses_only_nullable_columns_and_can_span_multiple_bytes(): void
    {
        $fields = [['name' => 'label', 'type' => 'C', 'length' => 5]];
        $values = ['fixed'];
        for ($index = 0; $index < 10; $index++) {
            $fields[] = ['name' => 'score'.$index, 'type' => 'N', 'length' => 3, 'flags' => 2];
            $values[] = (string) $index;
        }
        $fields[] = ['name' => '_NullFlags', 'type' => '0', 'length' => 2, 'flags' => 5];
        $values[] = "\x81\x02";
        $path = $this->workspace.'/bitmap.dbf';
        File::put($path, $this->dbf($fields, [$values]));
        $reader = new VisualFoxProDbfReader($path);
        $row = iterator_to_array($reader->records())[0];

        $this->assertSame('fixed', $row['label']);
        $this->assertNull($row['score0']);
        $this->assertSame('1', $row['score1']);
        $this->assertNull($row['score7']);
        $this->assertSame('8', $row['score8']);
        $this->assertNull($row['score9']);
        $this->assertSame('8102', $row['_nullflags']);
        $this->assertSame([$row], iterator_to_array($reader->records()), 'Repeated import passes must read the same values.');
    }

    public function test_varchar_length_bits_do_not_shift_nullable_score_bits(): void
    {
        $path = $this->workspace.'/varchar-bitmap.dbf';
        File::put($path, $this->dbf([
            ['name' => 'label', 'type' => 'V', 'length' => 4, 'flags' => 2],
            ['name' => 'final', 'type' => 'N', 'length' => 3, 'flags' => 2],
            ['name' => '_NullFlags', 'type' => '0', 'length' => 1, 'flags' => 5],
        ], [
            ['old', '32', "\x02"],
            ['old', '32', "\x04"],
        ]));
        $rows = iterator_to_array((new VisualFoxProDbfReader($path))->records());

        $this->assertNull($rows[0]['label']);
        $this->assertSame('32', $rows[0]['final']);
        $this->assertNull($rows[1]['final']);
    }

    public function test_nullable_dbf_without_a_null_bitmap_is_rejected_instead_of_importing_stale_values(): void
    {
        $path = $this->workspace.'/missing-bitmap.dbf';
        File::put($path, $this->dbf([
            ['name' => 'final', 'type' => 'N', 'length' => 3, 'flags' => 2],
        ], [['32']]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('สถานะ NULL');
        new VisualFoxProDbfReader($path);
    }

    public function test_dbf_with_an_undersized_null_bitmap_is_rejected(): void
    {
        $fields = [];
        for ($index = 0; $index < 9; $index++) {
            $fields[] = ['name' => 'score'.$index, 'type' => 'N', 'length' => 3, 'flags' => 2];
        }
        $fields[] = ['name' => '_NullFlags', 'type' => '0', 'length' => 1, 'flags' => 5];
        $path = $this->workspace.'/short-bitmap.dbf';
        File::put($path, $this->dbf($fields, []));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('สถานะ NULL');
        new VisualFoxProDbfReader($path);
    }

    /**
     * @param  list<array{name: string, type: string, length: int, flags?: int}>  $fields
     * @param  list<list<string>>  $records
     */
    private function dbf(array $fields, array $records): string
    {
        $headerLength = 32 + (count($fields) * 32) + 1;
        $recordLength = 1 + array_sum(array_column($fields, 'length'));
        $binary = chr(0x30).str_repeat("\0", 3)
            .pack('Vvv', count($records), $headerLength, $recordLength)
            .str_repeat("\0", 20);

        foreach ($fields as $field) {
            $binary .= str_pad($field['name'], 11, "\0")
                .$field['type'].str_repeat("\0", 4)
                .chr($field['length']).chr(0).chr($field['flags'] ?? 0).str_repeat("\0", 13);
        }
        $binary .= chr(0x0D);
        foreach ($records as $record) {
            $binary .= ' ';
            foreach ($fields as $index => $field) {
                $binary .= str_pad(substr($record[$index] ?? '', 0, $field['length']), $field['length']);
            }
        }

        return $binary.chr(0x1A);
    }
}
