<?php

namespace App\Support;

use Generator;
use RuntimeException;

final class VisualFoxProDbfReader
{
    /** @var resource */
    private $handle;

    /** @var list<array{name: string, type: string, length: int, decimal: int}> */
    private array $fields = [];

    private int $recordCount;

    private int $headerLength;

    private int $recordLength;

    public function __construct(private readonly string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('ไม่สามารถเปิดไฟล์ DBF ได้');
        }
        $this->handle = $handle;
        $this->readHeader();
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    /** @return list<array{name: string, type: string, length: int, decimal: int}> */
    public function fields(): array
    {
        return $this->fields;
    }

    public function recordCount(): int
    {
        return $this->recordCount;
    }

    /** @return Generator<int, array<string, string|null>> */
    public function records(): Generator
    {
        if (fseek($this->handle, $this->headerLength) !== 0) {
            throw new RuntimeException('ตำแหน่งข้อมูลในไฟล์ DBF ไม่ถูกต้อง');
        }

        for ($index = 0; $index < $this->recordCount; $index++) {
            $buffer = fread($this->handle, $this->recordLength);
            if ($buffer === false || strlen($buffer) !== $this->recordLength) {
                throw new RuntimeException("ไฟล์ DBF สิ้นสุดก่อนจำนวนระเบียนที่ระบุ (ระเบียน {$index})");
            }
            if ($buffer[0] === '*') {
                continue;
            }

            $offset = 1;
            $record = [];
            foreach ($this->fields as $field) {
                $raw = substr($buffer, $offset, $field['length']);
                $record[$field['name']] = $this->decode($raw, $field['type']);
                $offset += $field['length'];
            }
            yield $record;
        }
    }

    private function readHeader(): void
    {
        $header = fread($this->handle, 32);
        if ($header === false || strlen($header) !== 32) {
            throw new RuntimeException('ส่วนหัวไฟล์ DBF ไม่สมบูรณ์');
        }

        $values = unpack('Vrecords/vheader/vrecord', substr($header, 4, 8));
        $this->recordCount = (int) ($values['records'] ?? 0);
        $this->headerLength = (int) ($values['header'] ?? 0);
        $this->recordLength = (int) ($values['record'] ?? 0);
        $size = filesize($this->path);

        if ($this->recordCount < 0 || $this->recordCount > 10_000_000
            || $this->headerLength < 33 || $this->headerLength > 65_535
            || $this->recordLength < 2 || $this->recordLength > 65_535
            || $size === false || $this->headerLength > $size) {
            throw new RuntimeException('โครงสร้างส่วนหัว DBF ไม่ผ่านการตรวจสอบ');
        }

        if (fseek($this->handle, 32) !== 0) {
            throw new RuntimeException('ไม่สามารถอ่านโครงสร้างคอลัมน์ DBF ได้');
        }

        while (ftell($this->handle) < $this->headerLength - 1) {
            $descriptor = fread($this->handle, 32);
            if ($descriptor === false || $descriptor === '') {
                throw new RuntimeException('โครงสร้างคอลัมน์ DBF ไม่สมบูรณ์');
            }
            if (ord($descriptor[0]) === 0x0D) {
                break;
            }
            if (strlen($descriptor) !== 32) {
                throw new RuntimeException('โครงสร้างคอลัมน์ DBF มีขนาดไม่ถูกต้อง');
            }

            $name = strtolower(trim(str_replace("\0", '', substr($descriptor, 0, 11))));
            $name = preg_replace('/[^a-z0-9_]/', '', $name) ?? '';
            $length = ord($descriptor[16]);
            if ($name === '' || $length < 1) {
                throw new RuntimeException('พบชื่อหรือขนาดคอลัมน์ DBF ที่ไม่ถูกต้อง');
            }
            if (in_array($name, array_column($this->fields, 'name'), true)) {
                throw new RuntimeException("พบชื่อคอลัมน์ซ้ำใน DBF: {$name}");
            }

            $this->fields[] = [
                'name' => $name,
                'type' => strtoupper($descriptor[11]),
                'length' => $length,
                'decimal' => ord($descriptor[17]),
            ];
        }

        if ($this->fields === [] || 1 + array_sum(array_column($this->fields, 'length')) > $this->recordLength) {
            throw new RuntimeException('จำนวนคอลัมน์และความยาวระเบียน DBF ไม่สัมพันธ์กัน');
        }
    }

    private function decode(string $raw, string $type): ?string
    {
        if (in_array($type, ['I', 'T', 'B', 'G', 'P', 'Y'], true)) {
            return trim($raw, "\0 \t\r\n") === '' ? null : bin2hex($raw);
        }

        // Numeric, date and logical DBF fields are ASCII. Avoid running iconv
        // millions of times for values that cannot contain Thai text.
        if (! in_array($type, ['C', 'M', 'V'], true)) {
            $value = trim($raw, "\0 \t\r\n");

            return $value === '' ? null : $value;
        }

        $decoded = @iconv('Windows-874', 'UTF-8//IGNORE', $raw);
        if ($decoded === false) {
            $decoded = @iconv('TIS-620', 'UTF-8//IGNORE', $raw);
        }
        $value = trim($decoded === false ? $raw : $decoded, "\0 \t\r\n");

        return $value === '' ? null : $value;
    }
}
