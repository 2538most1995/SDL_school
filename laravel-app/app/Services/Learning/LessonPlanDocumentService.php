<?php

namespace App\Services\Learning;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Throwable;

final class LessonPlanDocumentService
{
    private const DOCUMENT_COLUMNS = ['external_url', 'word_url', 'pdf_path', 'pdf_original_name', 'pdf_size', 'word_path', 'word_original_name', 'word_size', 'cover_path', 'page_count'];

    public function row(Request $request, int $id, bool $lock = false): object
    {
        $this->assertStaff($request);
        $row = $this->query($request)->where('id', $id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();
        abort_unless($row, 404);

        return $row;
    }

    public function rowForWrite(Request $request, int $id, bool $lock = false): object
    {
        $row = $this->row($request, $id, $lock);
        if ($request->user()->role === 'teacher') {
            abort_if((int) $row->teacher_id !== (int) $request->user()->id, 403, 'คุณไม่มีสิทธิ์แก้ไขแผนการสอนของครูท่านอื่น');
        }

        return $row;
    }

    private function query(Request $request): Builder
    {
        return DB::table('learning_lesson_plans')->where('district_id', $this->district($request));
    }

    public function save(Request $request, ?int $id = null): int
    {
        $columns = $this->assertWrite($request);
        $newPaths = [];
        $oldPaths = [];
        try {
            $savedId = DB::transaction(function () use ($request, $id, $columns, &$newPaths, &$oldPaths): int {
                $row = $id === null ? null : $this->rowForWrite($request, $id, true);
                $values = $this->validated($request, $row, $columns);
                $metadata = [
                    'title' => $values['title'], 'subject_code' => $values['subject'], 'education_level' => $values['level'],
                    'plan_scope' => $values['plan_scope'],
                    'academic_term' => $values['semester'], 'objectives' => $values['objectives'] ?? null,
                    'activities' => $values['activities'] ?? null, 'assessment' => $values['assessment'] ?? null,
                    'status' => $values['status'] ?? $row?->status ?? 'published', 'updated_at' => now(),
                    ...$this->legacyValues($request, $values, $row, $columns),
                ];
                $savedId = $id ?? (int) DB::table('learning_lesson_plans')->insertGetId([
                    ...$metadata, 'district_id' => $this->district($request), 'teacher_id' => $request->user()->id, 'created_at' => now(),
                ]);
                $document = $this->documentValues($request, $values, $row, $savedId, $newPaths);
                DB::table('learning_lesson_plans')->where('id', $savedId)->update([...$metadata, ...$document]);
                foreach (['pdf_path', 'word_path', 'cover_path'] as $column) {
                    $old = (string) ($row?->{$column} ?? '');
                    if ($old !== '' && $old !== ($document[$column] ?? null) && $this->ownedPath($this->district($request), $savedId, $old)) {
                        $oldPaths[] = $old;
                    }
                }
                $this->audit($request, $id === null ? 'created' : 'updated', $savedId, $document);

                return $savedId;
            });
        } catch (Throwable $error) {
            Storage::disk('local')->delete($newPaths);
            throw $error;
        }
        Storage::disk('local')->delete($oldPaths);

        return $savedId;
    }

    private function validated(Request $request, ?object $existing, array $columns): array
    {
        $required = $existing === null ? ['required'] : ['sometimes', 'required'];
        $titleLimit = $this->stringCapacity($columns['title'] ?? [], 220);
        $values = $request->validate([
            'title' => [...$required, 'string', "max:{$titleLimit}"], 'subject' => ['sometimes', 'nullable', 'string', 'max:32'],
            'plan_scope' => ['sometimes', 'required', Rule::in(['subject', 'all_subjects'])],
            'level' => [...$required, Rule::in(['1', '2', '3'])], 'semester' => [...$required, 'string', 'max:16'],
            'objectives' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'activities' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assessment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'source_type' => ['sometimes', Rule::in(['link', 'pdf', 'word'])],
            'external_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
            'word_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
            'pdf' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'max:20480'],
            'word' => ['sometimes', 'nullable', 'file', 'mimes:doc,docx', 'max:30720'],
            'cover_image' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=2000,max_height=2000'],
            'page_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
            'clear_pdf' => ['sometimes', 'nullable'],
            'remove_pdf' => ['sometimes', 'nullable'],
            'clear_word' => ['sometimes', 'nullable'],
            'remove_word' => ['sometimes', 'nullable'],
            'clear_cover' => ['sometimes', 'nullable'],
            'remove_cover' => ['sometimes', 'nullable'],
        ], ['title.max' => "ชื่อแผนการสอนต้องไม่เกิน {$titleLimit} ตัวอักษร"]);
        $values = [
            'plan_scope' => $existing?->plan_scope ?? 'subject',
            'title' => $existing?->title, 'subject' => $existing?->subject_code, 'level' => $existing?->education_level,
            'semester' => $existing?->academic_term, 'objectives' => $existing?->objectives,
            'activities' => $existing?->activities, 'assessment' => $existing?->assessment,
            ...$values,
        ];
        if ($values['plan_scope'] === 'all_subjects') {
            // The level is still mandatory; the document covers every subject
            // in that level rather than pretending to belong to one course.
            $values['subject'] = '';
        } elseif (blank($values['subject']) && ($existing === null || ($existing->plan_scope ?? 'subject') !== 'subject' || $request->has('subject'))) {
            throw ValidationException::withMessages(['subject' => 'กรุณาระบุรายวิชาหรือเลือกแผนรวมทุกวิชา']);
        }
        $url = (string) ($request->has('external_url') ? ($request->input('external_url') ?? '') : ($existing?->external_url ?? ''));
        if ($url !== '' && ! self::safeExternalUrl($url)) {
            throw ValidationException::withMessages(['external_url' => 'กรุณาใช้ลิงก์ HTTP หรือ HTTPS ที่ไม่มีข้อมูลเข้าสู่ระบบหรืออักขระผิดรูปแบบ']);
        }
        $wordUrl = (string) ($request->has('word_url') ? ($request->input('word_url') ?? '') : ($existing?->word_url ?? ''));
        if ($wordUrl !== '' && ! self::safeExternalUrl($wordUrl)) {
            throw ValidationException::withMessages(['word_url' => 'กรุณาใช้ลิงก์ Word ที่เป็น HTTP หรือ HTTPS ที่ไม่มีข้อมูลเข้าสู่ระบบหรืออักขระผิดรูปแบบ']);
        }
        if (isset($values['pdf']) && strtolower($values['pdf']->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['pdf' => 'ต้องแนบไฟล์นามสกุล PDF เท่านั้น']);
        }
        if (isset($values['word']) && ! in_array(strtolower($values['word']->getClientOriginalExtension()), ['doc', 'docx'], true)) {
            throw ValidationException::withMessages(['word' => 'ต้องแนบไฟล์เอกสาร Word (.doc หรือ .docx) เท่านั้น']);
        }
        $hasLink = $url !== '' || $wordUrl !== '';
        $source = $values['source_type'] ?? ($hasLink ? 'link' : (isset($values['pdf']) || filled($existing?->pdf_path ?? null) ? 'pdf' : (isset($values['word']) || filled($existing?->word_path ?? null) ? 'word' : null)));
        if ($source === 'link' && ! $hasLink) {
            throw ValidationException::withMessages(['external_url' => 'กรุณาระบุลิงก์แผนการสอน (PDF หรือ Word)']);
        }
        if ($source === 'pdf' && ! isset($values['pdf']) && ! filled($existing?->pdf_path ?? null)) {
            throw ValidationException::withMessages(['pdf' => 'กรุณาแนบไฟล์ PDF']);
        }
        if ($source === 'word' && ! isset($values['word']) && ! filled($existing?->word_path ?? null)) {
            throw ValidationException::withMessages(['word' => 'กรุณาแนบไฟล์ Word']);
        }
        if ($source === null && (isset($values['cover_image']) || isset($values['page_count']))) {
            throw ValidationException::withMessages(['source_type' => 'กรุณาแนบลิงก์, PDF หรือ Word ก่อนสร้างปก']);
        }
        $values['resolved_source'] = $source;

        return $values;
    }

    private function stringCapacity(array $column, int $maximum): int
    {
        return preg_match('/^(?:var)?char\((\d+)\)/i', $column['type'] ?? '', $match)
            ? min($maximum, (int) $match[1]) : $maximum;
    }

    private function legacyValues(Request $request, array $values, ?object $row, array $columns): array
    {
        // Adopted tables retain these columns, including a required subject
        // without a default. Keep aliases in the same transaction as canonical
        // fields; never change an existing creator when an admin edits a plan.
        $aliases = [
            'subject' => $values['subject'], 'level' => (string) $values['level'], 'semester' => $values['semester'],
            'created_by' => $row === null ? $request->user()->id : ($row->created_by ?? $row->teacher_id ?? null),
        ];
        if (isset($columns['file_url'])) {
            $url = match ($values['resolved_source']) {
                'link' => (string) ($values['external_url'] ?? $row?->external_url ?? ''),
                'pdf' => '',
                default => (string) ($row?->file_url ?? ''),
            };
            // The full URL lives in external_url. Do not truncate cloud links
            // or expose a private PDF path to satisfy the old VARCHAR(500).
            $aliases['file_url'] = mb_strlen($url) <= $this->stringCapacity($columns['file_url'], 2048) ? $url : '';
        }

        return array_intersect_key($aliases, $columns);
    }

    public static function safeExternalUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL) || preg_match('/[\s\\\\\x00-\x1f\x7f]/u', $url)
            || preg_match('/%(?:0[0-9a-f]|1[0-9a-f]|7f)/i', $url) || preg_match('/%(?![a-f0-9]{2})/i', $url)) {
            return false;
        }
        $parts = parse_url($url);

        return is_array($parts) && in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            && filled($parts['host'] ?? null) && ! array_key_exists('user', $parts) && ! array_key_exists('pass', $parts);
    }

    private function documentValues(Request $request, array $values, ?object $row, int $id, array &$newPaths): array
    {
        $document = [];
        foreach (self::DOCUMENT_COLUMNS as $column) {
            $document[$column] = $row?->{$column} ?? null;
        }
        $url = $request->has('external_url') ? $request->input('external_url') : ($row?->external_url ?? null);
        $wordUrl = $request->has('word_url') ? $request->input('word_url') : ($row?->word_url ?? null);
        $changedLink = $values['resolved_source'] === 'link' && ($url !== ($row?->external_url ?? null) || $wordUrl !== ($row?->word_url ?? null));
        if ($changedLink) {
            if ($url !== ($row?->external_url ?? null) && filled($url)) {
                $document['pdf_path'] = null;
                $document['pdf_original_name'] = null;
                $document['pdf_size'] = null;
                $document['cover_path'] = null;
                $document['page_count'] = null;
            }
            if ($wordUrl !== ($row?->word_url ?? null) && filled($wordUrl)) {
                $document['word_path'] = null;
                $document['word_original_name'] = null;
                $document['word_size'] = null;
            }
        } elseif (isset($values['pdf'])) {
            $document['cover_path'] = null;
        }
        $document['external_url'] = $values['resolved_source'] === 'link' ? (filled($url) ? $url : null) : null;
        $document['word_url'] = filled($wordUrl) ? $wordUrl : null;
        if ($request->boolean('clear_pdf') || $request->boolean('remove_pdf')) {
            $document['pdf_path'] = null;
            $document['pdf_original_name'] = null;
            $document['pdf_size'] = null;
        }
        if ($request->boolean('clear_word') || $request->boolean('remove_word')) {
            $document['word_path'] = null;
            $document['word_original_name'] = null;
            $document['word_size'] = null;
        }
        if ($request->boolean('clear_cover') || $request->boolean('remove_cover')) {
            $document['cover_path'] = null;
        }
        if (isset($values['pdf'])) {
            $path = $values['pdf']->storeAs("learning/lesson-plans/{$this->district($request)}/{$id}", Str::uuid().'.pdf', 'local');
            abort_unless(is_string($path), 500, 'ไม่สามารถบันทึกไฟล์ได้');
            $newPaths[] = $path;
            $document['pdf_path'] = $path;
            $document['pdf_original_name'] = mb_substr($this->filename($values['pdf']->getClientOriginalName()), 0, 255);
            $document['pdf_size'] = $values['pdf']->getSize();
        }
        if (isset($values['word'])) {
            $extension = strtolower($values['word']->getClientOriginalExtension()) === 'doc' ? 'doc' : 'docx';
            $path = $values['word']->storeAs("learning/lesson-plans/{$this->district($request)}/{$id}", Str::uuid().'.'.$extension, 'local');
            abort_unless(is_string($path), 500, 'ไม่สามารถบันทึกไฟล์ Word ได้');
            $newPaths[] = $path;
            $document['word_path'] = $path;
            $document['word_original_name'] = mb_substr($this->filename($values['word']->getClientOriginalName()), 0, 255);
            $document['word_size'] = $values['word']->getSize();
        }
        if (isset($values['cover_image'])) {
            $extension = match ($values['cover_image']->getMimeType()) {
                'image/png' => 'png',
                'image/webp' => 'webp',
                default => 'jpg',
            };
            $path = $values['cover_image']->storeAs("learning/lesson-plans/{$this->district($request)}/{$id}", Str::uuid().'.'.$extension, 'local');
            abort_unless(is_string($path), 500, 'ไม่สามารถบันทึกหน้าปกได้');
            $newPaths[] = $path;
            $document['cover_path'] = $path;
        }
        if (array_key_exists('page_count', $values)) {
            $document['page_count'] = $values['page_count'];
        }

        return $document;
    }

    public function destroy(Request $request, int $id): void
    {
        $this->assertWrite($request);
        $row = DB::transaction(function () use ($request, $id): object {
            $row = $this->rowForWrite($request, $id, true);
            $this->query($request)->where('id', $id)->delete();
            $this->audit($request, 'deleted', $id, []);

            return $row;
        });
        foreach (['pdf_path', 'word_path', 'cover_path'] as $column) {
            $path = (string) ($row->{$column} ?? '');
            if ($this->ownedPath($this->district($request), $id, $path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function file(Request $request, int $id, bool $cover = false): BinaryFileResponse
    {
        $row = $this->row($request, $id);
        $path = (string) ($cover ? ($row->cover_path ?? '') : ($row->pdf_path ?? ''));
        abort_unless($this->ownedPath($this->district($request), $id, $path) && Storage::disk('local')->exists($path), 404);
        $mime = $cover ? match (pathinfo($path, PATHINFO_EXTENSION)) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        } : 'application/pdf';
        $filename = $cover ? 'lesson-plan-cover.'.pathinfo($path, PATHINFO_EXTENSION) : $this->filename((string) ($row->pdf_original_name ?: $row->title.'.pdf'));
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        if ($request->isMethod('GET') && preg_match('/^bytes=(\d+)-(\d*)$/D', $request->header('Range', ''), $range)) {
            $size = Storage::disk('local')->size($path);
            abort_if((float) $range[1] >= $size || ($range[2] !== '' && (float) $range[2] < (float) $range[1]),
                416, 'ช่วงข้อมูลไฟล์ไม่ถูกต้อง', ['Content-Range' => 'bytes */'.$size]);
        }

        return new BinaryFileResponse(Storage::disk('local')->path($path), 200, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $cover ? 'lesson-plan-cover.'.pathinfo($path, PATHINFO_EXTENSION) : 'lesson-plan.pdf'),
        ], false);
    }

    public function word(Request $request, int $id): BinaryFileResponse
    {
        $row = $this->row($request, $id);
        $path = (string) ($row->word_path ?? '');
        abort_unless($this->ownedPath($this->district($request), $id, $path) && Storage::disk('local')->exists($path), 404);
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'doc' ? 'doc' : 'docx';
        $mime = $extension === 'doc' ? 'application/msword' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $filename = $this->filename((string) ($row->word_original_name ?: $row->title.'.'.$extension));

        return new BinaryFileResponse(Storage::disk('local')->path($path), 200, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition('attachment', $filename, 'lesson-plan.'.$extension),
        ], false);
    }

    private function filename(string $name): string
    {
        $name = trim((string) preg_replace('~[\x00-\x1f\x7f/\\\\]+~u', ' ', $name));

        return $name === '' ? 'lesson-plan.pdf' : mb_substr($name, 0, 180);
    }

    private function ownedPath(int $district, int $id, string $path): bool
    {
        return preg_match('#^learning/lesson-plans/'.preg_quote((string) $district, '#').'/'.preg_quote((string) $id, '#').'/[0-9a-f-]+\.(?:pdf|png|jpg|webp|doc|docx)$#D', $path) === 1;
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function assertStaff(Request $request): void
    {
        abort_unless($request->user() && in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true) && $this->district($request) > 0, 403);
    }

    private function assertWrite(Request $request): array
    {
        $this->assertStaff($request);
        abort_unless(config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        $columns = array_column(Schema::getColumns('learning_lesson_plans'), null, 'name');
        abort_unless(array_diff([...self::DOCUMENT_COLUMNS, 'plan_scope'], array_keys($columns)) === [], 503, 'กรุณาให้ผู้ดูแลรัน php artisan migrate --force เพื่อเปิดใช้เอกสารแผนการสอน');

        return $columns;
    }

    private function audit(Request $request, string $action, int $id, array $document): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'district_id' => $this->district($request), 'event' => "learning.lesson-plans.{$action}",
            'auditable_type' => 'system_learning_lesson-plans', 'auditable_id' => $id, 'ip_address' => $request->ip(), 'before' => null,
            'after' => json_encode(['has_pdf' => filled($document['pdf_path'] ?? null), 'has_word' => filled($document['word_path'] ?? null), 'has_cover' => filled($document['cover_path'] ?? null), 'page_count' => $document['page_count'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
