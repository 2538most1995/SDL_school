<?php

namespace App\Support;

use Generator;

/** Edits a private copy, retaining descriptors, encoding, memo pointers and record positions. */
final class VisualFoxProDbfEditor
{
    private $handle;

    private array $fields = [];

    private array $nullBits = [];

    private ?array $bitmap = null;

    private int $headerLength;

    private int $recordLength;

    private int $count;

    public function __construct(private readonly string $path)
    {
        $reader = new VisualFoxProDbfReader($path);
        $this->handle = fopen($path, 'r+b');
        $header = fread($this->handle, 32);
        $layout = unpack('Vcount/vheader/vrecord', substr($header, 4, 8));
        $this->count = $layout['count'];
        $this->headerLength = $layout['header'];
        $this->recordLength = $layout['record'];
        if (filesize($path) < $this->headerLength + $this->count * $this->recordLength) {
            throw new DbfWriteException('DBF มีข้อมูลไม่ครบตามจำนวนระเบียน');
        }
        $offset = 1;
        $bit = 0;
        $foxpro = in_array(ord($header[0]), [0x30, 0x31, 0x32], true);
        foreach ($reader->fields() as $field) {
            $descriptor = fread($this->handle, 32);
            if ($foxpro && in_array($field['type'], ['V', 'Q'], true)) {
                $bit++;
            }
            if ($foxpro && (ord($descriptor[18]) & 2) !== 0) {
                $this->nullBits[$field['name']] = $bit++;
            }
            $this->fields[$field['name']] = [...$field, 'offset' => $offset];
            if ($field['name'] === '_nullflags') {
                $this->bitmap = ['offset' => $offset, 'length' => $field['length']];
            }
            $offset += $field['length'];
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function fields(): array
    {
        return $this->fields;
    }

    public function nullFlags(int $index): ?string
    {
        if ($this->bitmap === null) {
            return null;
        }
        fseek($this->handle, $this->headerLength + $index * $this->recordLength + $this->bitmap['offset']);

        return bin2hex(fread($this->handle, $this->bitmap['length']));
    }

    public function records(): Generator
    {
        // Keep physical record indices, including gaps for deleted records.
        $reader = new VisualFoxProDbfReader($this->path);
        yield from $reader->records(true);
    }

    public function requireFields(array $names): void
    {
        foreach ($names as $name) {
            if (! isset($this->fields[$name])) {
                throw new DbfWriteException('DBF ขาดช่องที่จำเป็น: '.strtoupper($name));
            }
        }
    }

    public function update(int $index, array $values): void
    {
        if ($index < 0 || $index >= $this->count) {
            throw new DbfWriteException('ไม่พบระเบียน DBF ที่ต้องการปรับ');
        }
        fseek($this->handle, $this->headerLength + $index * $this->recordLength);
        $record = fread($this->handle, $this->recordLength);
        $record = $this->encode($record, $values);
        fseek($this->handle, $this->headerLength + $index * $this->recordLength);
        $this->write($record);
        $this->stamp();
        fflush($this->handle);
    }

    public function append(array $values): int
    {
        $record = ' '.str_repeat("\0", $this->recordLength - 1);
        foreach ($this->fields as $name => $field) {
            if (in_array($field['type'], ['V', 'Q', 'M', 'G', 'P'], true)) {
                throw new DbfWriteException('ไม่รองรับการเพิ่มระเบียน DBF ที่มีช่องชนิด '.$field['type']);
            }
            if (in_array($field['type'], ['C', 'N', 'F', 'D'], true)) {
                $record = substr_replace($record, str_repeat(' ', $field['length']), $field['offset'], $field['length']);
            } elseif ($field['type'] === 'L') {
                $record = substr_replace($record, 'F', $field['offset'], 1);
            }
            $this->setNull($record, $name, true);
        }
        $record = $this->encode($record, $values);
        $index = $this->count;
        fseek($this->handle, $this->headerLength + $this->count * $this->recordLength);
        $this->write($record.chr(0x1A));
        $this->count++;
        ftruncate($this->handle, $this->headerLength + $this->count * $this->recordLength + 1);
        fseek($this->handle, 4);
        $this->write(pack('V', $this->count));
        $this->stamp();
        fflush($this->handle);

        return $index;
    }

    private function encode(string $record, array $values): string
    {
        $this->requireFields(array_keys($values));
        foreach ($values as $name => $value) {
            $field = $this->fields[$name];
            if (! in_array($field['type'], ['C', 'N', 'F', 'L'], true)) {
                throw new DbfWriteException('ไม่รองรับการแก้ช่อง '.strtoupper($name).' ชนิด '.$field['type']);
            }
            $encoded = '';
            if ($value !== null) {
                if ($field['type'] === 'L') {
                    $encoded = $value ? 'T' : 'F';
                } elseif ($field['type'] === 'C') {
                    $encoded = @iconv('UTF-8', 'Windows-874', (string) $value);
                    if ($encoded === false) {
                        throw new DbfWriteException('ข้อความในช่อง '.strtoupper($name).' ไม่สามารถเขียนเป็นภาษาไทยรูปแบบเดิมได้');
                    }
                } else {
                    if (! is_numeric($value) || ! is_finite((float) $value)) {
                        throw new DbfWriteException('ค่าช่อง '.strtoupper($name).' ต้องเป็นตัวเลข');
                    }
                    $precision = $field['decimal'];
                    if (abs((float) $value - round((float) $value, $precision)) > 0.0000001) {
                        throw new DbfWriteException('ช่อง '.strtoupper($name).' รองรับทศนิยม '.$precision.' ตำแหน่ง แต่ได้รับ '.$value.' กรุณาแก้คะแนนก่อนส่งออก (ระบบไม่ปัดค่า)');
                    }
                    $encoded = number_format((float) $value, $precision, '.', '');
                }
            }
            if (strlen($encoded) > $field['length']) {
                throw new DbfWriteException('ข้อมูลช่อง '.strtoupper($name).' ยาวหรือมีค่าเกินขนาด DBF เดิม');
            }
            $record = substr_replace($record, str_pad($encoded, $field['length'], ' ', $field['type'] === 'C' ? STR_PAD_RIGHT : STR_PAD_LEFT), $field['offset'], $field['length']);
            $this->setNull($record, $name, $value === null);
        }

        return $record;
    }

    private function setNull(string &$record, string $name, bool $null): void
    {
        if (! isset($this->nullBits[$name])) {
            return;
        }
        $bit = $this->nullBits[$name];
        $offset = $this->bitmap['offset'] + intdiv($bit, 8);
        $mask = 1 << ($bit % 8);
        $record[$offset] = chr($null ? ord($record[$offset]) | $mask : ord($record[$offset]) & ~$mask);
    }

    private function stamp(): void
    {
        fseek($this->handle, 1);
        $this->write(chr((int) date('Y') - 1900).chr((int) date('n')).chr((int) date('j')));
    }

    private function write(string $bytes): void
    {
        if (fwrite($this->handle, $bytes) !== strlen($bytes)) {
            throw new DbfWriteException('เขียน DBF ไม่สำเร็จ กรุณาตรวจพื้นที่ว่าง');
        }
    }
}
