<?php

namespace Tests\Unit;

use App\Domain\Students\Support\LegacyTableSet;
use App\Support\LegacyStudentCodebookReader;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class LegacyStudentCodebookReaderTest extends TestCase
{
    private string $root;

    private string $batch = 'import_1700000000_abc';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/sdl-codebook-'.bin2hex(random_bytes(6));
        foreach ([0, 2, 3] as $level) {
            File::ensureDirectoryExists($this->root.'/'.$this->batch.'/school/'.$level);
            file_put_contents($this->root.'/'.$this->batch.'/school/'.$level.'/STUDENT.DBF', 'not read');
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_it_reads_windows_874_labels_from_the_matching_batch_and_level(): void
    {
        $this->dbf('/school/2/s_grade.dbf', [['02', 'ป.6']]);
        $this->dbf('/school/3/S_GRADE.DBF', [['02', 'ระดับ 4']]);
        $this->dbf('/school/0/RELIGION.DBF', [['01', 'พุทธ']]);
        $reader = new LegacyStudentCodebookReader($this->root);
        $this->assertSame(['2' => 'ป.6'], $reader->dictionary($this->set(2), 's_grade'));
        $this->assertSame(['2' => 'ระดับ 4'], $reader->dictionary($this->set(3), 's_grade'));
        $this->assertSame(['1' => 'พุทธ'], $reader->dictionary($this->set(3), 'religion'));
        $this->assertNull($reader->dictionary($this->set(1), 's_grade'));
        $this->assertNull($reader->dictionary($this->set(3), 'student'));
        $this->assertNull($reader->dictionary($this->set(3, 'import_1700000001_other'), 's_grade'));
        $this->assertNull($reader->dictionary($this->set(3, '../'.$this->batch), 's_grade'));
    }

    public function test_conflicting_reference_rows_corrupt_files_and_external_symlinks_fail_closed(): void
    {
        $this->dbf('/school/3/s_grade.dbf', [['01', 'ชื่อแรก'], ['1', 'ชื่ออื่น'], ['02', 'ป.6']]);
        file_put_contents($this->root.'/'.$this->batch.'/school/3/religion.dbf', 'corrupt');
        $reader = new LegacyStudentCodebookReader($this->root);
        $this->assertSame(['2' => 'ป.6'], $reader->dictionary($this->set(3), 's_grade'));
        $this->assertSame([], $reader->dictionary($this->set(3), 'religion'));
        $outside = $this->root.'/outside.dbf';
        file_put_contents($outside, 'outside');
        symlink($outside, $this->root.'/'.$this->batch.'/school/3/occupat.dbf');
        $this->assertSame([], $reader->dictionary($this->set(3), 'occupat'));
    }

    public function test_it_does_not_choose_another_school_when_source_directories_are_ambiguous(): void
    {
        $this->dbf('/school/3/s_grade.dbf', [['01', 'โรงเรียนแรก']]);
        File::ensureDirectoryExists($this->root.'/'.$this->batch.'/other-school/3');
        file_put_contents($this->root.'/'.$this->batch.'/other-school/3/STUDENT.DBF', 'not read');
        $reader = new LegacyStudentCodebookReader($this->root);
        $this->assertNull($reader->dictionary($this->set(3), 's_grade'));
    }

    private function set(int $level, ?string $batch = null): LegacyTableSet
    {
        return new LegacyTableSet(1, '', $batch ?? $this->batch, $level, '', '', '', null, null, null);
    }

    private function dbf(string $relativePath, array $rows): void
    {
        $header = str_repeat("\0", 32);
        $header[0] = chr(3);
        $header = substr_replace($header, pack('V', count($rows)), 4, 4);
        $header = substr_replace($header, pack('vv', 97, 104), 8, 4);
        $descriptors = '';
        foreach (['ID' => 3, 'NAME' => 100] as $name => $length) {
            $descriptor = str_repeat("\0", 32);
            $descriptor = substr_replace($descriptor, $name, 0, strlen($name));
            $descriptor[11] = 'C';
            $descriptor[16] = chr($length);
            $descriptors .= $descriptor;
        }
        $contents = $header.$descriptors."\r";
        foreach ($rows as [$code, $name]) {
            $contents .= ' '.str_pad($code, 3).str_pad(iconv('UTF-8', 'Windows-874', $name), 100);
        }
        file_put_contents($this->root.'/'.$this->batch.$relativePath, $contents."\x1a");
    }
}
