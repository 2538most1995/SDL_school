<?php

namespace Tests\Unit;

use App\Services\Legacy\LegacyZipImportService;
use App\Support\VisualFoxProDbfReader;
use Illuminate\Http\UploadedFile;
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
        config()->set('legacy.write_enabled', true);
        config()->set('legacy.zip_root', $this->workspace.'/zips');
        config()->set('legacy.extract_root', $this->workspace.'/extracted');
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

    /**
     * @param  list<array{name: string, type: string, length: int}>  $fields
     * @param  list<list<string>>  $records
     */
    private function dbf(array $fields, array $records): string
    {
        $headerLength = 32 + (count($fields) * 32) + 1;
        $recordLength = 1 + array_sum(array_column($fields, 'length'));
        $binary = chr(0x03).str_repeat("\0", 3)
            .pack('Vvv', count($records), $headerLength, $recordLength)
            .str_repeat("\0", 20);

        foreach ($fields as $field) {
            $binary .= str_pad($field['name'], 11, "\0")
                .$field['type'].str_repeat("\0", 4)
                .chr($field['length']).chr(0).str_repeat("\0", 14);
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
