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
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class LessonPlanDocumentService
{
    private const DOCUMENT_COLUMNS = ['external_url', 'pdf_path', 'pdf_original_name', 'pdf_size', 'cover_path', 'page_count'];

    public function row(Request $request, int $id, bool $lock = false): object
    {
        $this->assertStaff($request);
        $row = $this->query($request)->where('id', $id)->when($lock, fn (Builder $query) => $query->lockForUpdate())->first();
        abort_unless($row, 404);

        return $row;
    }

    private function query(Request $request): Builder
    {
        return DB::table('learning_lesson_plans')->where('district_id', $this->district($request))
            ->when($request->user()->role === 'teacher', fn (Builder $query) => $query->where('teacher_id', (int) $request->user()->id));
    }

    public function save(Request $request, ?int $id = null): int
    {
        $this->assertWrite($request);
        $newPaths = [];
        $oldPaths = [];
        try {
            $savedId = DB::transaction(function () use ($request, $id, &$newPaths, &$oldPaths): int {
                $row = $id === null ? null : $this->row($request, $id, true);
                $values = $this->validated($request, $row);
                $metadata = [
                    'title' => $values['title'], 'subject_code' => $values['subject'], 'education_level' => $values['level'],
                    'academic_term' => $values['semester'], 'objectives' => $values['objectives'] ?? null,
                    'activities' => $values['activities'] ?? null, 'assessment' => $values['assessment'] ?? null,
                    'status' => $values['status'] ?? $row?->status ?? 'published', 'updated_at' => now(),
                ];
                $savedId = $id ?? (int) DB::table('learning_lesson_plans')->insertGetId([
                    ...$metadata, 'district_id' => $this->district($request), 'teacher_id' => $request->user()->id, 'created_at' => now(),
                ]);
                $document = $this->documentValues($request, $values, $row, $savedId, $newPaths);
                DB::table('learning_lesson_plans')->where('id', $savedId)->update([...$metadata, ...$document]);
                foreach (['pdf_path', 'cover_path'] as $column) {
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

    private function validated(Request $request, ?object $existing): array
    {
        $required = $existing === null ? 'required' : 'sometimes';
        $values = $request->validate([
            'title' => [$required, 'string', 'max:220'], 'subject' => [$required, 'string', 'max:32'],
            'level' => [$required, Rule::in(['1', '2', '3'])], 'semester' => [$required, 'string', 'max:16'],
            'objectives' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'activities' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'assessment' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'source_type' => ['sometimes', Rule::in(['link', 'pdf'])],
            'external_url' => ['sometimes', 'nullable', 'string', 'url:http,https', 'max:2048'],
            'pdf' => ['sometimes', 'nullable', 'file', 'mimes:pdf', 'max:20480'],
            'cover_image' => ['sometimes', 'nullable', 'file', 'image', 'mimes:jpg,jpeg,png', 'max:2048', 'dimensions:min_width=1,min_height=1,max_width=2000,max_height=2000'],
            'page_count' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $values = [
            'title' => $existing?->title, 'subject' => $existing?->subject_code, 'level' => $existing?->education_level,
            'semester' => $existing?->academic_term, 'objectives' => $existing?->objectives,
            'activities' => $existing?->activities, 'assessment' => $existing?->assessment,
            ...$values,
        ];
        $url = (string) ($values['external_url'] ?? $existing?->external_url ?? '');
        if ($url !== '' && ! self::safeExternalUrl($url)) {
            throw ValidationException::withMessages(['external_url' => 'กรุณาใช้ลิงก์ HTTP หรือ HTTPS ที่ไม่มีข้อมูลเข้าสู่ระบบหรืออักขระผิดรูปแบบ']);
        }
        if (isset($values['pdf']) && strtolower($values['pdf']->getClientOriginalExtension()) !== 'pdf') {
            throw ValidationException::withMessages(['pdf' => 'ต้องแนบไฟล์นามสกุล PDF เท่านั้น']);
        }
        $source = $values['source_type'] ?? ($url !== '' ? 'link' : (isset($values['pdf']) || filled($existing?->pdf_path ?? null) ? 'pdf' : null));
        if ($source === 'link' && $url === '') {
            throw ValidationException::withMessages(['external_url' => 'กรุณาระบุลิงก์แผนการสอน']);
        }
        if ($source === 'pdf' && ! isset($values['pdf']) && ! filled($existing?->pdf_path ?? null)) {
            throw ValidationException::withMessages(['pdf' => 'กรุณาแนบไฟล์ PDF']);
        }
        if ($source === null && (isset($values['cover_image']) || isset($values['page_count']))) {
            throw ValidationException::withMessages(['source_type' => 'กรุณาแนบลิงก์หรือ PDF ก่อนสร้างปก']);
        }
        $values['resolved_source'] = $source;

        return $values;
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
        $url = $values['external_url'] ?? $row?->external_url ?? null;
        $changedLink = $values['resolved_source'] === 'link' && $url !== ($row?->external_url ?? null);
        $replacing = isset($values['pdf']) || $changedLink;
        if ($replacing) {
            $document = array_fill_keys(self::DOCUMENT_COLUMNS, null);
        }
        $document['external_url'] = $values['resolved_source'] === 'link' ? $url : null;
        if (isset($values['pdf'])) {
            $path = $values['pdf']->storeAs("learning/lesson-plans/{$this->district($request)}/{$id}", Str::uuid().'.pdf', 'local');
            abort_unless(is_string($path), 500, 'ไม่สามารถบันทึกไฟล์ได้');
            $newPaths[] = $path;
            $document['pdf_path'] = $path;
            $document['pdf_original_name'] = mb_substr($this->filename($values['pdf']->getClientOriginalName()), 0, 255);
            $document['pdf_size'] = $values['pdf']->getSize();
        }
        if (isset($values['cover_image'])) {
            $extension = $values['cover_image']->getMimeType() === 'image/png' ? 'png' : 'jpg';
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
            $row = $this->row($request, $id, true);
            $this->query($request)->where('id', $id)->delete();
            $this->audit($request, 'deleted', $id, []);

            return $row;
        });
        foreach (['pdf_path', 'cover_path'] as $column) {
            $path = (string) ($row->{$column} ?? '');
            if ($this->ownedPath($this->district($request), $id, $path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function file(Request $request, int $id, bool $cover = false): StreamedResponse
    {
        $row = $this->row($request, $id);
        $path = (string) ($cover ? ($row->cover_path ?? '') : ($row->pdf_path ?? ''));
        abort_unless($this->ownedPath($this->district($request), $id, $path) && Storage::disk('local')->exists($path), 404);
        $mime = $cover ? (str_ends_with($path, '.png') ? 'image/png' : 'image/jpeg') : 'application/pdf';
        $filename = $cover ? 'lesson-plan-cover.'.pathinfo($path, PATHINFO_EXTENSION) : $this->filename((string) ($row->pdf_original_name ?: $row->title.'.pdf'));
        $disposition = $request->boolean('download') ? 'attachment' : 'inline';

        return Storage::disk('local')->response($path, $filename, [
            'Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => HeaderUtils::makeDisposition($disposition, $filename, $cover ? 'lesson-plan-cover.'.pathinfo($path, PATHINFO_EXTENSION) : 'lesson-plan.pdf'),
        ], $disposition);
    }

    private function filename(string $name): string
    {
        $name = trim((string) preg_replace('~[\x00-\x1f\x7f/\\\\]+~u', ' ', $name));

        return $name === '' ? 'lesson-plan.pdf' : mb_substr($name, 0, 180);
    }

    private function ownedPath(int $district, int $id, string $path): bool
    {
        return preg_match('#^learning/lesson-plans/'.preg_quote((string) $district, '#').'/'.preg_quote((string) $id, '#').'/[0-9a-f-]+\.(?:pdf|png|jpg)$#D', $path) === 1;
    }

    private function district(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    private function assertStaff(Request $request): void
    {
        abort_unless($request->user() && in_array($request->user()->role, ['teacher', 'admin', 'super_admin'], true) && $this->district($request) > 0, 403);
    }

    private function assertWrite(Request $request): void
    {
        $this->assertStaff($request);
        abort_unless(config('system_data.write_enabled'), 503, 'ระบบเขียนข้อมูลยังไม่เปิดใช้งาน');
        abort_unless(Schema::hasColumns('learning_lesson_plans', self::DOCUMENT_COLUMNS), 503, 'กรุณาให้ผู้ดูแลรัน php artisan migrate --force เพื่อเปิดใช้เอกสารแผนการสอน');
    }

    private function audit(Request $request, string $action, int $id, array $document): void
    {
        DB::table('audit_logs')->insert([
            'user_id' => $request->user()->id, 'district_id' => $this->district($request), 'event' => "learning.lesson-plans.{$action}",
            'auditable_type' => 'system_learning_lesson-plans', 'auditable_id' => $id, 'ip_address' => $request->ip(), 'before' => null,
            'after' => json_encode(['has_pdf' => filled($document['pdf_path'] ?? null), 'has_cover' => filled($document['cover_path'] ?? null), 'page_count' => $document['page_count'] ?? null], JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
