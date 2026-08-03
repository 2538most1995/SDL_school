<?php

namespace App\Support;

use RuntimeException;

/**
 * Reads Visual FoxPro text memo blocks without modifying the legacy database.
 *
 * The old DBF importer converted the four-byte FPT pointer as Windows-874 text
 * and stored that pointer in MySQL. This reader reverses that conversion and
 * resolves the real value from the matching student.FPT file.
 */
final class LegacyFptMemoReader
{
    /** @var array<string, string|null> */
    private array $paths = [];

    /** @var array<string, array<string, array<string, string>>> */
    private array $studentRecords = [];

    /** @var array<string, resource> */
    private array $handles = [];

    /** @var array<string, int> */
    private array $blockSizes = [];

    /** @var array<string, string|null> */
    private array $values = [];

    public function __construct(private readonly string $root) {}

    public function readStudentMemo(
        string $batchKey,
        int $level,
        ?string $storedPointer,
        ?string $studentCode = null,
        ?string $field = null,
    ): ?string {
        if (preg_match('/^import_[0-9]+_[a-f0-9]+$/', $batchKey) !== 1
            || ! in_array($level, [0, 1, 2, 3], true)) {
            return null;
        }

        $pointer = null;
        if ($studentCode !== null && $field !== null && in_array($field, ['addr', 'curaddr', 'phone', 'curphone', 'email'], true)) {
            $rawPointer = $this->rawStudentField($batchKey, $level, $studentCode, $field);
            $pointer = $rawPointer === null || strlen($rawPointer) !== 4
                ? null
                : (int) (unpack('Vpointer', $rawPointer)['pointer'] ?? 0);
        }
        $pointer = $pointer > 0 ? $pointer : $this->pointer((string) $storedPointer);
        if ($pointer === null) {
            return null;
        }

        $path = $this->studentFptPath($batchKey, $level);
        if ($path === null) {
            return null;
        }

        $cacheKey = $path.'|'.$pointer;
        if (array_key_exists($cacheKey, $this->values)) {
            return $this->values[$cacheKey];
        }

        try {
            $handle = $this->handle($path);
            $blockSize = $this->blockSize($path, $handle);
            $offset = $pointer * $blockSize;
            $fileSize = filesize($path);
            if ($fileSize === false || $offset < 0 || $offset + 8 > $fileSize) {
                return $this->values[$cacheKey] = null;
            }

            if (fseek($handle, $offset) !== 0) {
                return $this->values[$cacheKey] = null;
            }

            $header = fread($handle, 8);
            if ($header === false || strlen($header) !== 8) {
                return $this->values[$cacheKey] = null;
            }

            $metadata = unpack('Ntype/Nlength', $header);
            $length = (int) ($metadata['length'] ?? 0);
            if ((int) ($metadata['type'] ?? 0) !== 1
                || $length < 1
                || $length > 1_048_576
                || $offset + 8 + $length > $fileSize) {
                return $this->values[$cacheKey] = null;
            }

            $raw = fread($handle, $length);
            if ($raw === false || strlen($raw) !== $length) {
                return $this->values[$cacheKey] = null;
            }

            $decoded = iconv('Windows-874', 'UTF-8//IGNORE', rtrim($raw, "\0"));
            $cleaned = preg_replace('/\s+/u', ' ', trim((string) $decoded)) ?? '';

            return $this->values[$cacheKey] = $cleaned === '' ? null : $cleaned;
        } catch (RuntimeException) {
            return $this->values[$cacheKey] = null;
        }
    }

    public function readStudentDate(string $batchKey, int $level, string $studentCode, string $field): ?string
    {
        if (preg_match('/^import_[0-9]+_[a-f0-9]+$/', $batchKey) !== 1
            || ! in_array($level, [0, 1, 2, 3], true)
            || ! in_array($field, ['lastupdate', 'insertdate'], true)) {
            return null;
        }

        $raw = $this->rawStudentField($batchKey, $level, $studentCode, $field);
        if ($raw === null || strlen($raw) !== 8) {
            return null;
        }

        $parts = unpack('Vjulian/Vmilliseconds', $raw);
        $julian = (int) ($parts['julian'] ?? 0);
        if ($julian < 2_300_000 || $julian > 2_600_000 || ! function_exists('jdtogregorian')) {
            return null;
        }

        $gregorian = explode('/', jdtogregorian($julian));
        if (count($gregorian) !== 3) {
            return null;
        }

        [$month, $day, $year] = array_map('intval', $gregorian);
        if ($year < 1990 || $year > 2100 || ! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    public function __destruct()
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function pointer(string $value): ?int
    {
        if ($value === '' || mb_strlen($value) > 4) {
            return null;
        }

        $bytes = iconv('UTF-8', 'Windows-874//IGNORE', $value);
        if ($bytes === false || $bytes === '' || strlen($bytes) > 4) {
            return null;
        }

        // Visual FoxPro memo pointers are unsigned 32-bit little-endian values.
        // The legacy importer trimmed trailing NUL bytes before saving them.
        $unpacked = unpack('Vpointer', str_pad($bytes, 4, "\0", STR_PAD_RIGHT));
        $pointer = (int) ($unpacked['pointer'] ?? 0);

        return $pointer > 0 ? $pointer : null;
    }

    private function studentFptPath(string $batchKey, int $level): ?string
    {
        return $this->studentPath($batchKey, $level, 'fpt');
    }

    private function studentDbfPath(string $batchKey, int $level): ?string
    {
        return $this->studentPath($batchKey, $level, 'dbf');
    }

    private function studentPath(string $batchKey, int $level, string $extension): ?string
    {
        $cacheKey = $batchKey.'|'.$level.'|'.$extension;
        if (array_key_exists($cacheKey, $this->paths)) {
            return $this->paths[$cacheKey];
        }

        $root = realpath($this->root);
        $batchRoot = realpath(rtrim($this->root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$batchKey);
        if ($root === false || $batchRoot === false || ! str_starts_with($batchRoot.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            return $this->paths[$cacheKey] = null;
        }

        $matches = glob($batchRoot.DIRECTORY_SEPARATOR.'*'.DIRECTORY_SEPARATOR.$level.DIRECTORY_SEPARATOR.'*') ?: [];
        foreach ($matches as $candidate) {
            if (strcasecmp(basename($candidate), 'student.'.$extension) !== 0) {
                continue;
            }

            $path = realpath($candidate);
            if ($path !== false && is_file($path) && str_starts_with($path, $batchRoot.DIRECTORY_SEPARATOR)) {
                return $this->paths[$cacheKey] = $path;
            }
        }

        return $this->paths[$cacheKey] = null;
    }

    private function rawStudentField(string $batchKey, int $level, string $studentCode, string $field): ?string
    {
        $cacheKey = $batchKey.'|'.$level;
        if (! array_key_exists($cacheKey, $this->studentRecords)) {
            $this->studentRecords[$cacheKey] = $this->loadStudentRecords($batchKey, $level);
        }

        return $this->studentRecords[$cacheKey][$studentCode][strtolower($field)] ?? null;
    }

    /** @return array<string, array<string, string>> */
    private function loadStudentRecords(string $batchKey, int $level): array
    {
        $path = $this->studentDbfPath($batchKey, $level);
        if ($path === null) {
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return [];
        }

        try {
            $header = fread($handle, 32);
            if ($header === false || strlen($header) !== 32) {
                return [];
            }

            $recordCount = (int) (unpack('Vcount', substr($header, 4, 4))['count'] ?? 0);
            $headerLength = (int) (unpack('vlength', substr($header, 8, 2))['length'] ?? 0);
            $recordLength = (int) (unpack('vlength', substr($header, 10, 2))['length'] ?? 0);
            if ($recordCount < 1 || $recordCount > 1_000_000 || $headerLength < 33 || $recordLength < 2) {
                return [];
            }

            $fields = [];
            $offset = 1;
            while (ftell($handle) < $headerLength - 1) {
                $descriptor = fread($handle, 32);
                if ($descriptor === false || strlen($descriptor) !== 32 || ord($descriptor[0]) === 13) {
                    break;
                }

                $name = strtolower(rtrim(substr($descriptor, 0, 11), "\0 "));
                $length = ord($descriptor[16]);
                if ($name !== '') {
                    $fields[$name] = ['offset' => $offset, 'length' => $length];
                }
                $offset += $length;
            }

            if (! isset($fields['id'])) {
                return [];
            }

            $wanted = ['addr', 'curaddr', 'phone', 'curphone', 'email', 'lastupdate', 'insertdate'];
            $records = [];
            if (fseek($handle, $headerLength) !== 0) {
                return [];
            }

            for ($index = 0; $index < $recordCount; $index++) {
                $record = fread($handle, $recordLength);
                if ($record === false || strlen($record) !== $recordLength) {
                    break;
                }
                if ($record[0] === '*') {
                    continue;
                }

                $code = trim(substr($record, $fields['id']['offset'], $fields['id']['length']));
                if ($code === '') {
                    continue;
                }

                foreach ($wanted as $name) {
                    if (isset($fields[$name])) {
                        $records[$code][$name] = substr($record, $fields[$name]['offset'], $fields[$name]['length']);
                    }
                }
            }

            return $records;
        } finally {
            fclose($handle);
        }
    }

    /** @return resource */
    private function handle(string $path)
    {
        if (isset($this->handles[$path]) && is_resource($this->handles[$path])) {
            return $this->handles[$path];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open legacy FPT memo file.');
        }

        return $this->handles[$path] = $handle;
    }

    /** @param resource $handle */
    private function blockSize(string $path, $handle): int
    {
        if (isset($this->blockSizes[$path])) {
            return $this->blockSizes[$path];
        }

        if (fseek($handle, 6) !== 0) {
            throw new RuntimeException('Unable to read legacy FPT header.');
        }

        $bytes = fread($handle, 2);
        $size = $bytes === false || strlen($bytes) !== 2
            ? 0
            : (int) (unpack('nsize', $bytes)['size'] ?? 0);
        if ($size < 32 || $size > 65_535) {
            throw new RuntimeException('Invalid legacy FPT block size.');
        }

        return $this->blockSizes[$path] = $size;
    }
}
