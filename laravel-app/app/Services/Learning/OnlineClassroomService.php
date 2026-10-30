<?php

namespace App\Services\Learning;

use App\Domain\Students\Services\StudentDirectoryService;
use App\Domain\Students\Support\AcademicTerm;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class OnlineClassroomService
{
    public function __construct(private LearningScorebookService $scorebooks, private StudentDirectoryService $directory) {}

    public function ready(): void
    {
        foreach (['learning_classroom_activities', 'learning_classroom_submissions', 'learning_classroom_images'] as $table) {
            abort_unless(Schema::hasTable($table), 503, 'ห้องเรียนออนไลน์ยังไม่พร้อม กรุณาให้ผู้ดูแลรัน php artisan migrate --force');
        }
    }

    /** Merge current-term ITW and web registrations without using saved student snapshots as authority. */
    public function catalog(User $viewer, int $districtId): array
    {
        $source = $this->scorebooks->assignmentCatalog($viewer, $districtId);
        $students = array_values(array_filter($this->directory->accessibleStudents($viewer), fn ($student) => $student->districtId === $districtId));
        if ($viewer->role === 'student') {
            abort_if(count($students) > 1, 403, 'ข้อมูลนักศึกษาไม่ชัดเจน กรุณาติดต่อผู้ดูแล');
        }
        $term = $source['selected_term'] ?? AcademicTerm::latest(array_map(fn ($student) => $student->currentTerm, $students));
        $rows = $source['registrations'];
        $studentMap = [];
        foreach ($students as $student) {
            $studentMap[$student->level.'|'.$student->code] = $student;
        }
        if ($term !== null && $students !== []) {
            $web = DB::table('learning_course_registrations')->where('district_id', $districtId)->where('academic_term', $term)
                ->whereIn('student_code', array_map(fn ($student) => $student->code, $students))
                ->get(['student_code', 'education_level', 'compulsory_subjects', 'elective_subjects']);
            foreach ($web as $registration) {
                $student = $studentMap[$registration->education_level.'|'.$registration->student_code] ?? null;
                if ($student === null) {
                    continue;
                }
                foreach (array_merge($this->decode($registration->compulsory_subjects), $this->decode($registration->elective_subjects)) as $subject) {
                    if (empty($subject['registered']) || ! empty($subject['transferred']) || empty($subject['code'])) {
                        continue;
                    }
                    $rows[] = ['level' => $student->level, 'student_code' => $student->code, 'student_name' => $student->fullName(),
                        'group_code' => $student->groupCode, 'group_name' => $student->groupName,
                        'subject_code' => (string) $subject['code'], 'subject_name' => (string) ($subject['name'] ?? $subject['code'])];
                }
            }
        }
        $unique = [];
        foreach ($rows as $row) {
            $unique[$row['level'].'|'.$row['student_code'].'|'.$row['subject_code']] = $row;
        }
        $rows = array_values($unique);
        $subjects = [];
        foreach ($rows as $row) {
            $key = $row['level'].'|'.$row['subject_code'];
            $subjects[$key] ??= ['code' => $row['subject_code'], 'name' => $row['subject_name'], 'level' => (int) $row['level'],
                'level_label' => [1 => 'ประถมศึกษา', 2 => 'มัธยมศึกษาตอนต้น', 3 => 'มัธยมศึกษาตอนปลาย'][(int) $row['level']], 'groups' => [], 'student_count' => 0];
            $subjects[$key]['groups'][$row['group_code']] = ['code' => $row['group_code'], 'name' => $row['group_name']];
            $subjects[$key]['student_count']++;
        }
        foreach ($subjects as &$subject) {
            $subject['groups'] = array_values($subject['groups']);
        }
        unset($subject);

        return ['term' => $term, 'terms' => $source['terms'], 'subjects' => array_values($subjects), 'registrations' => $rows];
    }

    public function workspace(User $viewer, int $districtId, array $filters): array
    {
        $catalog = $this->catalog($viewer, $districtId);
        $subject = null;
        foreach ($catalog['subjects'] as $item) {
            if (empty($filters['subject_code']) || ($item['code'] === $filters['subject_code'] && $item['level'] === (int) ($filters['education_level'] ?? 0))) {
                $subject = $item;
                break;
            }
        }
        abort_if(! empty($filters['subject_code']) && $subject === null, 404);
        $groupPairs = collect($catalog['registrations'])->filter(fn ($item) => $subject !== null && $item['subject_code'] === $subject['code'] && (int) $item['level'] === $subject['level'])
            ->unique(fn ($item) => $item['group_code'].'|'.$item['group_name'])->values();
        $activityQuery = $subject === null ? null : DB::table('learning_classroom_activities')
            ->where('district_id', $districtId)->where('academic_term', $catalog['term'])
            ->where('subject_code', $subject['code'])->where('education_level', $subject['level'])
            ->when($viewer->role === 'student', fn ($query) => $query->where('status', 'published'))
            ->when($viewer->role === 'teacher', fn ($query) => $query->where('created_by', $viewer->id))
            ->where(function ($query) use ($groupPairs): void {
                foreach ($groupPairs as $group) {
                    $query->orWhere(function ($part) use ($group): void {
                        $part->whereIn('target_group', ['', $group['group_code'], $group['group_name']])
                            ->where(fn ($scope) => $scope->whereNull('audience_groups')->orWhereJsonContains('audience_groups', $group['group_code']));
                    });
                }
            });
        $activityCounts = ['all' => 0, 'knowledge' => 0, 'video' => 0, 'worksheet' => 0, 'quiz' => 0];
        if ($activityQuery !== null) {
            $totals = (clone $activityQuery)->selectRaw('type, COUNT(*) as total')->groupBy('type')->pluck('total', 'type');
            foreach ($totals as $type => $total) {
                $activityCounts['all'] += (int) $total;
                if (array_key_exists($type, $activityCounts)) {
                    $activityCounts[$type] = (int) $total;
                }
            }
        }
        $activityPage = $activityQuery?->when(! empty($filters['activity_type']), fn ($query) => $query->where('type', $filters['activity_type']))
            ->orderByDesc('id')->paginate(50, ['*'], 'activity_page', (int) ($filters['activity_page'] ?? 1));
        $rows = collect($activityPage?->items() ?? []);
        $rows = $rows->filter(fn ($row) => $this->audience($catalog, $row) !== [])->values();
        if (isset($filters['activity_id']) && $viewer->role === 'teacher') {
            abort_unless(DB::table('learning_classroom_activities')->where('district_id', $districtId)->where('id', $filters['activity_id'])->where('created_by', $viewer->id)->exists(), 404);
        }
        $selected = isset($filters['activity_id']) ? $this->authorizedActivity($viewer, $districtId, (int) $filters['activity_id'], false, $catalog) : $rows->first();
        abort_if($selected !== null && ($subject === null || $selected->subject_code !== $subject['code'] || (int) $selected->education_level !== $subject['level'] || (! empty($filters['activity_type']) && $selected->type !== $filters['activity_type'])), 404);
        if ($selected !== null && ! $rows->contains('id', $selected->id)) {
            $rows->push($selected);
        }
        $ids = $rows->pluck('id')->all();
        $counts = DB::table('learning_classroom_submissions')->whereIn('activity_id', $ids)->groupBy('activity_id')->selectRaw('activity_id, COUNT(*) as total')->pluck('total', 'activity_id');
        $visibleCounts = $viewer->role === 'teacher' ? DB::table('learning_classroom_submissions')->whereIn('activity_id', $ids)
            ->where(function ($query) use ($rows, $catalog): void {
                foreach ($rows as $row) {
                    $codes = array_column($this->audience($catalog, $row), 'student_code');
                    $query->orWhere(fn ($scope) => $scope->where('activity_id', $row->id)->whereIn('student_code', $codes));
                }
            })->groupBy('activity_id')->selectRaw('activity_id, COUNT(*) as total')->pluck('total', 'activity_id') : $counts;
        $own = $viewer->role === 'student' ? DB::table('learning_classroom_submissions')->whereIn('activity_id', $ids)
            ->where('student_code', $viewer->student_code ?: $viewer->username)->get()->keyBy('activity_id') : collect();
        $lockedIds = Schema::hasTable('learning_classroom_task_items') ? DB::table('learning_classroom_task_items')->where('kind', 'activity')->whereIn('content_id', $ids)->pluck('content_id')->map(fn ($id) => (int) $id)->all() : [];
        $payloads = $rows->map(fn ($row) => $this->activityPayload($viewer, $row, (int) ($visibleCounts[$row->id] ?? 0), $own[$row->id] ?? null, $this->canEdit($viewer, $row, $catalog), in_array((int) $row->id, $lockedIds, true), (int) ($counts[$row->id] ?? 0) > 0));
        $selectedPayload = $selected === null ? null : $payloads->firstWhere('id', (string) $selected->id);
        if ($selected !== null && $viewer->role === 'student') {
            $deadline = app(ClassroomTaskService::class)->activityDeadline($viewer, $districtId, $selected, $catalog);
            $selectedPayload['due_at'] = $deadline === null ? null : Carbon::parse($deadline)->toIso8601String();
        }
        $submissions = [];
        $pagination = null;
        if ($selected !== null && $viewer->role !== 'student') {
            $audienceCodes = array_column($this->audience($catalog, $selected), 'student_code');
            $page = DB::table('learning_classroom_submissions')->where('activity_id', $selected->id)->whereIn('student_code', $audienceCodes)
                ->orderBy('id')->paginate(50, ['*'], 'page', (int) ($filters['page'] ?? 1));
            $submissions = array_map($this->submissionPayload(...), $page->items());
            $pagination = ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
        }

        return ['term' => $catalog['term'], 'subjects' => $catalog['subjects'], 'selected_subject' => $subject,
            'activities' => $payloads->filter(fn ($activity) => in_array((int) $activity['id'], array_map(fn ($row) => (int) $row->id, $activityPage?->items() ?? []), true))->values()->map(function ($activity) {
                unset($activity['questions'], $activity['content'], $activity['links']);

                return $activity;
            })->all(),
            'selected_activity' => $selectedPayload, 'submissions' => $submissions, 'submission_pagination' => $pagination, 'activity_counts' => $activityCounts,
            'activity_pagination' => $activityPage === null ? null : ['current_page' => $activityPage->currentPage(), 'last_page' => $activityPage->lastPage(), 'total' => $activityPage->total()]];
    }

    public function authorizedActivity(User $viewer, int $districtId, int $id, bool $write = false, ?array $catalog = null): object
    {
        $row = DB::table('learning_classroom_activities')->where('district_id', $districtId)->where('id', $id)->first();
        abort_if($row === null, 404);
        if ($write || $viewer->role === 'teacher') {
            abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
            abort_if($viewer->role === 'teacher' && (int) $row->created_by !== (int) $viewer->id, 403);
        }
        $catalog ??= $this->catalog($viewer, $districtId);
        abort_unless($row->academic_term === $catalog['term'] && $this->audience($catalog, $row) !== [], 404);
        if ($write) {
            abort_unless($this->canEdit($viewer, $row, $catalog), 403);
        }
        abort_if($viewer->role === 'student' && $row->status !== 'published', 404);

        return $row;
    }

    private function canEdit(User $viewer, object $row, array $catalog): bool
    {
        if ($viewer->role === 'student') {
            return false;
        }
        if ($viewer->role !== 'teacher') {
            return in_array($viewer->role, ['admin', 'super_admin'], true);
        }
        if ((int) $row->created_by !== (int) $viewer->id) {
            return false;
        }
        if ($row->target_group !== '') {
            return $this->audience($catalog, $row) !== [];
        }
        $groups = array_column(array_filter($catalog['registrations'], fn ($item) => $item['subject_code'] === $row->subject_code && (int) $item['level'] === (int) $row->education_level), 'group_code');

        return $row->audience_groups !== null && array_diff($this->decode($row->audience_groups), $groups) === [];
    }

    private function audience(array $catalog, object $activity): array
    {
        $groups = $activity->audience_groups === null ? null : $this->decode($activity->audience_groups);

        return array_values(array_filter($catalog['registrations'], fn ($row) => $row['subject_code'] === $activity->subject_code
            && (int) $row['level'] === (int) $activity->education_level
            && ($activity->target_group === '' || in_array($activity->target_group, [$row['group_code'], $row['group_name']], true))
            && ($groups === null || in_array($row['group_code'], $groups, true))));
    }

    public function save(User $viewer, int $districtId, array $values, ?int $id): int
    {
        abort_unless(in_array($viewer->role, ['teacher', 'admin', 'super_admin'], true), 403);
        $catalog = $this->catalog($viewer, $districtId);

        return DB::transaction(function () use ($viewer, $districtId, $values, $id, $catalog) {
            if ($id !== null) {
                DB::table('learning_classroom_activities')->where('id', $id)->lockForUpdate()->first();
                $this->authorizedActivity($viewer, $districtId, $id, true, $catalog);
                ClassroomTaskService::assertContentEditable('activity', $id);
                if (DB::table('learning_classroom_submissions')->where('activity_id', $id)->exists()) {
                    if (array_diff(array_keys($values), ['status']) !== []) {
                        throw ValidationException::withMessages(['questions' => 'มีผู้ส่งคำตอบแล้ว เปลี่ยนได้เฉพาะสถานะ กรุณาสร้างกิจกรรมใหม่หากต้องการแก้เนื้อหา']);
                    }
                    DB::table('learning_classroom_activities')->where('id', $id)->update(['status' => $values['status'], 'updated_at' => now()]);

                    return $id;
                }
            }
            // A status-only request also supports publishing or hiding a saved draft.
            if ($id !== null && array_keys($values) === ['status']) {
                DB::table('learning_classroom_activities')->where('id', $id)->update(['status' => $values['status'], 'updated_at' => now()]);

                return $id;
            }
            $subject = collect($catalog['subjects'])->first(fn ($subject) => $subject['code'] === $values['subject_code'] && $subject['level'] === (int) $values['education_level']);
            if ($subject === null) {
                throw ValidationException::withMessages(['subject_code' => 'รายวิชาไม่อยู่ในรายการลงทะเบียนปัจจุบันหรืออยู่นอกขอบเขตของคุณ']);
            }
            $group = trim((string) ($values['target_group'] ?? ''));
            if ($viewer->role === 'teacher' && $group === '') {
                throw ValidationException::withMessages(['target_group' => 'ครูต้องเลือกกลุ่มเรียนที่รับผิดชอบ ไม่สามารถเลือกทุกกลุ่มได้']);
            }
            if ($group !== '' && ! collect($subject['groups'])->contains(fn ($item) => in_array($group, [$item['code'], $item['name']], true))) {
                throw ValidationException::withMessages(['target_group' => 'กลุ่มเรียนอยู่นอกขอบเขตของรายวิชา']);
            }
            $questions = $this->validatedQuestions($values['questions'] ?? [], $values['type']);
            $imageIds = array_values(array_filter(array_column($questions, 'image_id')));
            if ($imageIds !== []) {
                $images = DB::table('learning_classroom_images')->where('district_id', $districtId)->whereIn('id', $imageIds)->get();
                foreach ($imageIds as $imageId) {
                    $image = $images->firstWhere('id', (int) $imageId);
                    abort_unless($image !== null && (($image->activity_id === null && (int) $image->created_by === (int) $viewer->id) || ($id !== null && (int) $image->activity_id === $id)), 422, 'รูปภาพไม่อยู่ในขอบเขตกิจกรรมนี้');
                }
            }
            $stored = ['subject_code' => $subject['code'], 'subject_name' => $subject['name'], 'education_level' => $subject['level'],
                'academic_term' => $catalog['term'], 'target_group' => $group,
                'audience_groups' => $viewer->role === 'teacher' ? json_encode(array_column($subject['groups'], 'code')) : null,
                'type' => $values['type'], 'title' => trim($values['title']), 'content' => $values['content'] ?? '',
                'links' => json_encode($values['links'] ?? [], JSON_UNESCAPED_UNICODE), 'questions' => json_encode($questions, JSON_UNESCAPED_UNICODE),
                'status' => $values['status'], 'due_at' => empty($values['due_at']) ? null : Carbon::parse($values['due_at'])->setTimezone(config('app.timezone'))->toDateTimeString(), 'updated_at' => now()];
            if ($id === null) {
                $id = DB::table('learning_classroom_activities')->insertGetId([...$stored, 'district_id' => $districtId, 'created_by' => $viewer->id, 'created_at' => now()]);
            } else {
                DB::table('learning_classroom_activities')->where('id', $id)->update($stored);
            }
            if ($imageIds !== []) {
                DB::table('learning_classroom_images')->whereIn('id', $imageIds)->update(['activity_id' => $id]);
            }

            return $id;
        });
    }

    private function validatedQuestions(array $questions, string $type): array
    {
        if (in_array($type, ['knowledge', 'video'], true)) {
            return [];
        }
        if ($questions === []) {
            throw ValidationException::withMessages(['questions' => 'เพิ่มคำถามอย่างน้อย 1 ข้อ']);
        }
        $ids = [];
        foreach ($questions as $i => &$question) {
            $id = (string) ($question['id'] ?? 'q'.($i + 1));
            $question['id'] = $id;
            if (isset($ids[$id])) {
                throw ValidationException::withMessages(['questions' => 'รหัสข้อคำถามต้องไม่ซ้ำกัน']);
            }
            $ids[$id] = true;
            $question['image_id'] = isset($question['image_id']) ? (string) $question['image_id'] : null;
            $question['options'] = array_values($question['options'] ?? []);
            $answer = $question['answer'] ?? null;
            $valid = match ($question['type']) {
                'essay' => true,
                'true_false' => in_array($answer, ['true', 'false'], true),
                'short_answer' => is_string($answer) && trim($answer) !== '',
                'single_choice' => count($question['options']) >= 2 && is_string($answer) && in_array($answer, $question['options'], true),
                'multiple_choice' => count($question['options']) >= 2 && is_array($answer) && count($answer) > 0 && count($answer) === count(array_unique($answer)) && array_diff($answer, $question['options']) === [],
                default => false,
            };
            if (! $valid || count($question['options']) !== count(array_unique($question['options']))) {
                throw ValidationException::withMessages(["questions.{$i}.answer" => 'ตรวจสอบตัวเลือกและเฉลยให้ตรงกับประเภทคำถาม ตัวเลือกต้องไม่ซ้ำ']);
            }
            if ($question['type'] === 'essay') {
                unset($question['answer']);
            }
        }
        unset($question);

        return $questions;
    }

    public function submit(User $viewer, int $districtId, int $id, array $answers): array
    {
        abort_unless($viewer->role === 'student', 403);
        $catalog = $this->catalog($viewer, $districtId);

        return DB::transaction(function () use ($viewer, $districtId, $id, $answers, $catalog) {
            DB::table('learning_classroom_activities')->where('id', $id)->lockForUpdate()->first();
            $activity = $this->authorizedActivity($viewer, $districtId, $id, false, $catalog);
            abort_if(in_array($activity->type, ['knowledge', 'video'], true), 422, 'ใบความรู้และคลิปวิดีโอไม่มีการส่งคำตอบ');
            $deadline = app(ClassroomTaskService::class)->activityDeadline($viewer, $districtId, $activity, $catalog);
            abort_if($deadline !== null && now()->gt($deadline), 422, 'พ้นกำหนดส่งแล้ว');
            $studentCode = (string) ($viewer->student_code ?: $viewer->username);
            abort_if(DB::table('learning_classroom_submissions')->where('activity_id', $id)->where('student_code', $studentCode)->exists(), 409, 'ส่งคำตอบแล้ว กิจกรรมนี้ส่งได้หนึ่งครั้ง');
            $questions = $this->decode($activity->questions);
            if (array_diff(array_keys($answers), array_column($questions, 'id')) !== []) {
                throw ValidationException::withMessages(['answers' => 'พบคำตอบที่ไม่ตรงกับข้อคำถาม']);
            }
            $score = 0;
            $manual = false;
            $clean = [];
            foreach ($questions as $question) {
                $answer = $answers[$question['id']] ?? null;
                $options = $question['options'];
                $valid = match ($question['type']) {
                    'multiple_choice' => is_array($answer) && $answer !== [] && count($answer) === count(array_unique($answer)) && array_diff($answer, $options) === [],
                    'single_choice' => is_string($answer) && in_array($answer, $options, true),
                    'true_false' => in_array($answer, ['true', 'false'], true),
                    default => is_string($answer) && trim($answer) !== '',
                };
                if (! $valid) {
                    throw ValidationException::withMessages(['answers' => 'กรุณาตอบทุกข้อและใช้คำตอบตามประเภทที่กำหนด']);
                }
                $clean[$question['id']] = $answer;
                if ($question['type'] === 'essay') {
                    $manual = true;

                    continue;
                }
                $expected = $question['answer'];
                if ($question['type'] === 'multiple_choice') {
                    sort($answer);
                    sort($expected);
                }
                if ($question['type'] === 'short_answer') {
                    $answer = mb_strtolower(trim($answer));
                    $expected = mb_strtolower(trim($expected));
                }
                if ($answer === $expected) {
                    $score += (float) $question['points'];
                }
            }
            $student = $this->audience($catalog, $activity)[0];
            $submissionId = DB::table('learning_classroom_submissions')->insertGetId(['activity_id' => $id, 'student_code' => $studentCode,
                'student_name' => $student['student_name'], 'answers' => json_encode($clean, JSON_UNESCAPED_UNICODE),
                'auto_score' => round($score, 2), 'score' => $manual ? null : round($score, 2), 'max_score' => round(array_sum(array_column($questions, 'points')), 2),
                'status' => $manual ? 'submitted' : 'reviewed', 'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            return $this->submissionPayload(DB::table('learning_classroom_submissions')->where('id', $submissionId)->first());
        });
    }

    public function review(User $viewer, int $districtId, int $id, int $submissionId, array $values): array
    {
        $activity = $this->authorizedActivity($viewer, $districtId, $id, true);
        $audience = array_column($this->audience($this->catalog($viewer, $districtId), $activity), 'student_code');
        $submission = DB::table('learning_classroom_submissions')->where('activity_id', $id)->where('id', $submissionId)->whereIn('student_code', $audience)->first();
        abort_if($submission === null, 404);
        if ((float) $values['score'] > (float) $submission->max_score) {
            throw ValidationException::withMessages(['score' => 'คะแนนต้องไม่เกินคะแนนเต็ม']);
        }
        DB::table('learning_classroom_submissions')->where('id', $submissionId)->update(['score' => $values['score'], 'feedback' => $values['feedback'] ?? null,
            'status' => 'reviewed', 'reviewed_by' => $viewer->id, 'updated_at' => now()]);

        return $this->submissionPayload(DB::table('learning_classroom_submissions')->where('id', $submissionId)->first());
    }

    public function delete(User $viewer, int $districtId, int $id): void
    {
        DB::transaction(function () use ($viewer, $districtId, $id) {
            DB::table('learning_classroom_activities')->where('id', $id)->lockForUpdate()->first();
            $this->authorizedActivity($viewer, $districtId, $id, true);
            ClassroomTaskService::assertContentEditable('activity', $id);
            abort_if(DB::table('learning_classroom_submissions')->where('activity_id', $id)->exists(), 422, 'มีผู้ส่งคำตอบแล้ว กรุณาเปลี่ยนเป็นฉบับร่างเพื่อซ่อนกิจกรรม');
            DB::table('learning_classroom_activities')->where('id', $id)->delete();
            // Keep private image metadata attached to the deleted activity: it then fails closed on read.
        });
    }

    private function activityPayload(User $viewer, object $row, int $count, ?object $submission, bool $canEdit, bool $contentLocked = false, ?bool $hasSubmissions = null): array
    {
        $questions = $this->decode($row->questions);
        foreach ($questions as &$question) {
            $question['image_url'] = empty($question['image_id']) ? null : '/api/v1/learning/classroom/images/'.$question['image_id'];
            if ($viewer->role === 'student') {
                unset($question['answer']);
            }
        }
        unset($question);

        return ['id' => (string) $row->id, 'subject_code' => $row->subject_code, 'subject_name' => $row->subject_name,
            'education_level' => (int) $row->education_level, 'target_group' => $row->target_group, 'type' => $row->type,
            'title' => $row->title, 'content' => $row->content ?? '', 'links' => $this->decode($row->links), 'status' => $row->status,
            'due_at' => $row->due_at === null ? null : Carbon::parse($row->due_at)->toIso8601String(), 'questions' => $questions, 'max_score' => round(array_sum(array_column($questions, 'points')), 2),
            'can_edit' => $canEdit, 'content_locked' => $contentLocked,
            'has_submissions' => $hasSubmissions ?? $count > 0, 'submission_count' => $count, 'submission' => $submission === null ? null : $this->submissionPayload($submission)];
    }

    private function submissionPayload(object $row): array
    {
        return ['id' => (string) $row->id, 'student_code' => $row->student_code, 'student_name' => $row->student_name,
            'answers' => $this->decode($row->answers), 'auto_score' => (float) $row->auto_score, 'score' => $row->score === null ? null : (float) $row->score,
            'max_score' => (float) $row->max_score, 'status' => $row->status, 'feedback' => $row->feedback, 'submitted_at' => Carbon::parse($row->submitted_at)->toIso8601String()];
    }

    private function decode(?string $value): array
    {
        $decoded = json_decode($value ?? '[]', true);

        return is_array($decoded) ? $decoded : [];
    }
}
