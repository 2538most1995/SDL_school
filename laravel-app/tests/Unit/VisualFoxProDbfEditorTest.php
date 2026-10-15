<?php

namespace Tests\Unit;

use App\Support\DbfWriteException;
use App\Support\VisualFoxProDbfEditor;
use App\Support\VisualFoxProDbfReader;
use PHPUnit\Framework\TestCase;

final class VisualFoxProDbfEditorTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = tempnam(sys_get_temp_dir(), 'sdl-dbf-edit-');
    }

    protected function tearDown(): void
    {
        unlink($this->path);
    }

    public function test_updates_preserve_layout_binary_fields_and_unrelated_null_bits(): void
    {
        $bytes = $this->fixture([
            ['std_code', 'C', 10, 0, 0], ['midterm', 'N', 3, 0, 2], ['final', 'N', 3, 0, 2],
            ['stamp', 'T', 8, 0, 4], ['memo', 'M', 4, 0, 0], ['_NullFlags', '0', 1, 0, 5],
        ], [['0000000001', ' 50', ' 32', "\x11\x22\x33\x44\x55\x66\x77\x88", "\x01\0\0\0", "\x83"]]);
        file_put_contents($this->path, $bytes);
        $editor = new VisualFoxProDbfEditor($this->path);
        $editor->update(0, ['midterm' => 0]);
        $row = iterator_to_array((new VisualFoxProDbfReader($this->path))->records())[0];
        $this->assertSame('0', $row['midterm']);
        $this->assertNull($row['final']);
        $this->assertSame('82', $row['_nullflags']);
        $actual = file_get_contents($this->path);
        $headerLength = unpack('v', substr($bytes, 8, 2))[1];
        $this->assertSame(substr($bytes, 4, $headerLength - 4), substr($actual, 4, $headerLength - 4));
        $this->assertSame(substr($bytes, $headerLength + 17, 12), substr($actual, $headerLength + 17, 12));
        $editor->update(0, ['midterm' => null]);
        $this->assertNull(iterator_to_array((new VisualFoxProDbfReader($this->path))->records())[0]['midterm']);
    }

    public function test_fractional_integer_scores_fail_without_changing_the_file(): void
    {
        $bytes = $this->fixture([['score', 'N', 3, 0, 0]], [[' 10']]);
        file_put_contents($this->path, $bytes);
        $editor = new VisualFoxProDbfEditor($this->path);
        try {
            $editor->update(0, ['score' => 17.5]);
            $this->fail('Fractional scores cannot be silently rounded.');
        } catch (DbfWriteException $exception) {
            $this->assertStringContainsString('ระบบไม่ปัดค่า', $exception->getMessage());
        }
        $this->assertSame($bytes, file_get_contents($this->path));
    }

    public function test_appends_preserve_backlink_header_and_mark_empty_nullable_scores(): void
    {
        $bytes = $this->fixture([['id', 'C', 10, 0, 0], ['score', 'N', 4, 1, 2], ['_NullFlags', '0', 1, 0, 5]], [['0000000001', '12.5', "\0"]]);
        file_put_contents($this->path, $bytes);
        $editor = new VisualFoxProDbfEditor($this->path);
        $this->assertSame(1, $editor->append(['id' => '0000000002', 'score' => null]));
        $reader = new VisualFoxProDbfReader($this->path);
        $rows = iterator_to_array($reader->records());
        $this->assertSame(2, $reader->recordCount());
        $this->assertSame('12.5', $rows[0]['score']);
        $this->assertNull($rows[1]['score']);
        $this->assertSame('0000000002', $rows[1]['id']);
        $actual = file_get_contents($this->path);
        $this->assertSame(substr($bytes, 8, unpack('v', substr($bytes, 8, 2))[1] - 8), substr($actual, 8, unpack('v', substr($bytes, 8, 2))[1] - 8));
        $this->assertSame(chr(0x1A), substr($actual, -1));
    }

    public function test_deleted_record_gaps_keep_the_correct_physical_index(): void
    {
        $bytes = $this->fixture([['id', 'C', 2, 0, 0], ['score', 'N', 2, 0, 0]], [['01', '10'], ['02', '20']]);
        $headerLength = unpack('v', substr($bytes, 8, 2))[1];
        $bytes[$headerLength] = '*';
        file_put_contents($this->path, $bytes);
        $editor = new VisualFoxProDbfEditor($this->path);
        $this->assertSame([1], array_keys(iterator_to_array($editor->records())));
        $editor->update(1, ['score' => 30]);
        $this->assertSame('30', iterator_to_array($editor->records())[1]['score']);
        $this->assertSame(substr($bytes, $headerLength, 5), substr(file_get_contents($this->path), $headerLength, 5));
    }

    public function test_thai_text_and_numeric_overflow_are_checked_in_original_byte_widths(): void
    {
        file_put_contents($this->path, $this->fixture([['name', 'C', 4, 0, 0], ['score', 'N', 2, 0, 0]], [['    ', ' 0']]));
        $editor = new VisualFoxProDbfEditor($this->path);
        $editor->update(0, ['name' => 'ไทย', 'score' => 99]);
        $this->assertSame('ไทย', iterator_to_array($editor->records())[0]['name']);
        $this->expectException(DbfWriteException::class);
        $editor->update(0, ['score' => 100]);
    }

    private function fixture(array $fields, array $records): string
    {
        $headerLength = 32 + count($fields) * 32 + 1 + 263;
        $recordLength = 1 + array_sum(array_column($fields, 2));
        $binary = chr(0x30).str_repeat("\0", 3).pack('Vvv', count($records), $headerLength, $recordLength).str_repeat("\0", 16).chr(1).chr(0x7C)."\0\0";
        foreach ($fields as [$name, $type, $length, $decimal, $flags]) {
            $binary .= str_pad($name, 11, "\0").$type.str_repeat("\0", 4).chr($length).chr($decimal).chr($flags).str_repeat("\0", 13);
        }
        $binary .= chr(0x0D).str_repeat("\0", 263);
        foreach ($records as $record) {
            $binary .= ' ';
            foreach ($fields as $i => $field) {
                $binary .= str_pad($record[$i], $field[2]);
            }
        }

        return $binary.chr(0x1A);
    }
}
