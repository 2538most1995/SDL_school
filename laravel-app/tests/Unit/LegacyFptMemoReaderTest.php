<?php

namespace Tests\Unit;

use App\Support\LegacyFptMemoReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyFptMemoReaderTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/sena-fpt-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root.'/import_123_abcdef/school/3');

        $blockSize = 64;
        $contents = str_repeat("\0", 300 * $blockSize);
        $contents = substr_replace($contents, pack('n', $blockSize), 6, 2);
        $contents = $this->putMemo($contents, 33, $blockSize, '081-234-5678');
        $contents = $this->putMemo($contents, 34, $blockSize, '88/9 หมู่ 4');
        $contents = $this->putMemo($contents, 289, $blockSize, '089-111-2233');

        file_put_contents($this->root.'/import_123_abcdef/school/3/STUDENT.FPT', $contents);
        file_put_contents(
            $this->root.'/import_123_abcdef/school/3/STUDENT.DBF',
            $this->studentDbf('STUDENT01', 289, gregoriantojd(7, 18, 2026)),
        );
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_resolves_windows_874_memo_pointers_from_visual_foxpro_fpt(): void
    {
        $reader = new LegacyFptMemoReader($this->root);

        $this->assertSame('081-234-5678', $reader->readStudentMemo('import_123_abcdef', 3, '!'));
        $this->assertSame('88/9 หมู่ 4', $reader->readStudentMemo('import_123_abcdef', 3, '"'));
    }

    public function test_it_rejects_untrusted_paths_and_invalid_pointers(): void
    {
        $reader = new LegacyFptMemoReader($this->root);

        $this->assertNull($reader->readStudentMemo('../import_123_abcdef', 3, '!'));
        $this->assertNull($reader->readStudentMemo('import_123_abcdef', 9, '!'));
        $this->assertNull($reader->readStudentMemo('import_123_abcdef', 3, 'not-a-pointer'));
        $this->assertNull($reader->readStudentDate('../import_123_abcdef', 3, 'STUDENT01', 'lastupdate'));
    }

    public function test_it_uses_the_raw_dbf_pointer_and_decodes_visual_foxpro_datetime(): void
    {
        $reader = new LegacyFptMemoReader($this->root);

        $this->assertSame(
            '089-111-2233',
            $reader->readStudentMemo('import_123_abcdef', 3, 'corrupted-import-value', 'STUDENT01', 'phone'),
        );
        $this->assertSame(
            '2026-07-18',
            $reader->readStudentDate('import_123_abcdef', 3, 'STUDENT01', 'lastupdate'),
        );
    }

    public function test_it_finds_student_memo_files_in_nested_import_directories(): void
    {
        $nestedRoot = $this->root.'/import_456_abcdef/backup/itw51/school/3';
        File::ensureDirectoryExists($nestedRoot);
        File::copy(
            $this->root.'/import_123_abcdef/school/3/STUDENT.FPT',
            $nestedRoot.'/student.fpt',
        );
        File::copy(
            $this->root.'/import_123_abcdef/school/3/STUDENT.DBF',
            $nestedRoot.'/student.dbf',
        );

        $reader = new LegacyFptMemoReader($this->root);

        $this->assertSame(
            '089-111-2233',
            $reader->readStudentMemo('import_456_abcdef', 3, null, 'STUDENT01', 'phone'),
        );
    }

    private function putMemo(string $contents, int $pointer, int $blockSize, string $value): string
    {
        $encoded = iconv('UTF-8', 'Windows-874//IGNORE', $value);
        $block = pack('NN', 1, strlen((string) $encoded)).$encoded;

        return substr_replace($contents, $block, $pointer * $blockSize, strlen($block));
    }

    private function studentDbf(string $studentCode, int $memoPointer, int $julianDay): string
    {
        $fields = [
            ['ID', 'C', 10],
            ['PHONE', 'M', 4],
            ['LASTUPDATE', 'T', 8],
        ];
        $headerLength = 32 + (count($fields) * 32) + 1;
        $recordLength = 1 + array_sum(array_column($fields, 2));
        $header = str_repeat("\0", 32);
        $header[0] = "\x30";
        $header = substr_replace($header, pack('V', 1), 4, 4);
        $header = substr_replace($header, pack('v', $headerLength), 8, 2);
        $header = substr_replace($header, pack('v', $recordLength), 10, 2);

        $descriptors = '';
        foreach ($fields as [$name, $type, $length]) {
            $descriptor = str_pad($name, 11, "\0").$type.str_repeat("\0", 20);
            $descriptor[16] = chr($length);
            $descriptors .= $descriptor;
        }

        $record = ' '
            .str_pad($studentCode, 10)
            .pack('V', $memoPointer)
            .pack('VV', $julianDay, 0);

        return $header.$descriptors."\x0D".$record."\x1A";
    }
}
