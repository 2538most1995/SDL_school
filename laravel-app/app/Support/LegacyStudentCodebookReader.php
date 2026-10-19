<?php

namespace App\Support;

use App\Domain\Students\Support\LegacyTableSet;
use RuntimeException;

/** Reads only reference dictionaries beside the authorized batch's STUDENT.DBF. */
final class LegacyStudentCodebookReader
{
    private array $directories = [];

    private array $dictionaries = [];

    public function __construct(private readonly string $root) {}

    /** @return array<string, string>|null Null means the reference file was not supplied. */
    public function dictionary(LegacyTableSet $set, string $name): ?array
    {
        if (! in_array($name, ['religion', 'nation', 'occupat', 'occtyp', 's_grade'], true)
            || preg_match('/^import_\d{10}_[A-Za-z0-9]+$/', $set->batchKey) !== 1
            || ! in_array($set->level, [1, 2, 3], true)) {
            return null;
        }
        $key = $set->batchKey.'|'.$set->level.'|'.$name;
        if (array_key_exists($key, $this->dictionaries)) {
            return $this->dictionaries[$key];
        }
        $directory = $this->studentDirectory($set);
        if ($directory === null) {
            return $this->dictionaries[$key] = null;
        }
        // S_GRADE is level-specific. Other reference tables may be shared in 0/.
        $directories = $name === 's_grade' ? [$directory] : [$directory, dirname($directory), dirname($directory).'/0'];
        foreach (array_unique($directories) as $candidateDirectory) {
            foreach (glob($candidateDirectory.'/*') ?: [] as $file) {
                if (strcasecmp(basename($file), $name.'.dbf') !== 0) {
                    continue;
                }
                $path = realpath($file);
                $batchRoot = realpath($this->root.'/'.$set->batchKey);
                if ($path === false || $batchRoot === false || is_link($file) || ! is_file($path)
                    || ! str_starts_with($path, $batchRoot.DIRECTORY_SEPARATOR)) {
                    return $this->dictionaries[$key] = [];
                }

                return $this->dictionaries[$key] = $this->readDictionary($path);
            }
        }

        return $this->dictionaries[$key] = null;
    }

    private function studentDirectory(LegacyTableSet $set): ?string
    {
        $key = $set->batchKey.'|'.$set->level;
        if (array_key_exists($key, $this->directories)) {
            return $this->directories[$key];
        }
        $root = realpath($this->root);
        $batchRoot = realpath($this->root.'/'.$set->batchKey);
        if ($root === false || $batchRoot === false || ! str_starts_with($batchRoot.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            return $this->directories[$key] = null;
        }
        $directories = [];
        try {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($batchRoot, \FilesystemIterator::SKIP_DOTS)) as $file) {
                if (! $file->isFile() || $file->isLink() || basename($file->getPath()) !== (string) $set->level
                    || strcasecmp($file->getBasename(), 'student.dbf') !== 0) {
                    continue;
                }
                $path = realpath($file->getPathname());
                if ($path !== false && str_starts_with($path, $batchRoot.DIRECTORY_SEPARATOR)) {
                    $directories[] = dirname($path);
                }
            }
        } catch (\UnexpectedValueException) {
            return $this->directories[$key] = null;
        }

        return $this->directories[$key] = count($directories) === 1 ? $directories[0] : null;
    }

    /** @return array<string, string> */
    private function readDictionary(string $path): array
    {
        try {
            if (filesize($path) > 8_388_608) {
                return [];
            }
            $reader = new VisualFoxProDbfReader($path);
            $fields = array_column($reader->fields(), 'type', 'name');
            if ($reader->recordCount() > 10000 || ($fields['id'] ?? null) !== 'C' || ($fields['name'] ?? null) !== 'C') {
                return [];
            }
            $labels = [];
            $conflicts = [];
            foreach ($reader->records() as $row) {
                $code = trim((string) ($row['id'] ?? ''));
                $name = trim((string) ($row['name'] ?? ''));
                if ($code === '' || $name === '') {
                    continue;
                }
                $key = ctype_digit($code) ? (ltrim($code, '0') ?: '0') : $code;
                if (isset($labels[$key]) && $labels[$key] !== $name) {
                    $conflicts[$key] = true;
                }
                $labels[$key] = $name;
            }

            return array_diff_key($labels, $conflicts);
        } catch (RuntimeException) {
            return [];
        }
    }
}
