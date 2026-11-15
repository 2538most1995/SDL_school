<?php

namespace App\Http\Controllers\Api\Settings;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class StatisticsReportPreferenceController extends Controller
{
    /** @var array<string, list<string>> */
    private const REPORT_CATEGORIES = [
        'new-students' => ['level', 'group', 'gender', 'learning_method', 'subject'],
        'registration-statistics' => ['level', 'group', 'gender', 'age', 'occupation', 'target_group', 'nationality', 'learning_method', 'subject'],
        'graduates' => ['level', 'group', 'gender', 'learning_method', 'subject'],
        'expected-graduates' => ['level', 'group', 'gender', 'nnet', 'learning_method', 'subject'],
        'transfers' => ['level', 'group', 'gender', 'learning_method', 'subject'],
        'gender-statistics' => ['level', 'group', 'gender', 'learning_method', 'subject'],
        'age-statistics' => ['level', 'group', 'gender', 'age_band', 'learning_method', 'subject'],
        'exam-disqualified' => ['level', 'group', 'gender', 'learning_method', 'subject'],
        'nnet-statistics' => ['level', 'group', 'gender', 'nnet', 'learning_method', 'subject'],
        'credit-pending' => ['level', 'group', 'gender', 'nnet', 'completion_status', 'learning_method', 'subject'],
    ];

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => ['required', Rule::in(array_keys(self::REPORT_CATEGORIES))],
        ]);
        $report = (string) $validated['report'];
        $row = DB::table('statistics_report_preferences')
            ->where('user_id', $request->user()->id)
            ->where('district_id', $this->districtId($request))
            ->where('report_key', $report)
            ->first();

        return response()->json([
            'data' => $row === null ? $this->defaults($report) : [
                'report' => $report,
                'vertical' => $this->decodeCategories($row->vertical_categories, $report, 'group'),
                'horizontal' => $this->decodeCategories($row->horizontal_categories, $report, 'level'),
                'orientation' => in_array($row->active_orientation, ['vertical', 'horizontal'], true)
                    ? $row->active_orientation
                    : 'vertical',
                'saved' => true,
            ],
            'meta' => ['source' => 'laravel_control_plane'],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'report' => ['required', Rule::in(array_keys(self::REPORT_CATEGORIES))],
            'vertical' => ['required', 'array', 'min:1', 'max:3'],
            'vertical.*' => ['required', 'string', 'distinct'],
            'horizontal' => ['required', 'array', 'min:1', 'max:3'],
            'horizontal.*' => ['required', 'string', 'distinct'],
            'orientation' => ['required', Rule::in(['vertical', 'horizontal'])],
        ]);
        $report = (string) $validated['report'];
        $vertical = $this->validateCategories($validated['vertical'], $report, 'vertical');
        $horizontal = $this->validateCategories($validated['horizontal'], $report, 'horizontal');
        $districtId = $this->districtId($request);
        $now = now();

        DB::table('statistics_report_preferences')->updateOrInsert(
            [
                'user_id' => $request->user()->id,
                'district_id' => $districtId,
                'report_key' => $report,
            ],
            [
                'vertical_categories' => json_encode($vertical, JSON_THROW_ON_ERROR),
                'horizontal_categories' => json_encode($horizontal, JSON_THROW_ON_ERROR),
                'active_orientation' => $validated['orientation'],
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );

        try {
            DB::table('audit_logs')->insert([
                'user_id' => $request->user()->id,
                'district_id' => $districtId,
                'event' => 'statistics.preference_updated',
                'auditable_type' => 'statistics_report_preference',
                'auditable_id' => null,
                'ip_address' => $request->ip(),
                'context' => json_encode(['report' => $report], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        } catch (\Throwable) {
            // Preferences remain usable if an adopted database has no audit table yet.
        }

        return response()->json([
            'data' => [
                'report' => $report,
                'vertical' => $vertical,
                'horizontal' => $horizontal,
                'orientation' => $validated['orientation'],
                'saved' => true,
            ],
            'meta' => ['source' => 'laravel_control_plane'],
        ]);
    }

    private function districtId(Request $request): int
    {
        return (int) $request->attributes->get('district_id');
    }

    /** @return array{report: string, vertical: list<string>, horizontal: list<string>, orientation: string, saved: bool} */
    private function defaults(string $report): array
    {
        return ['report' => $report, 'vertical' => ['group'], 'horizontal' => ['level'], 'orientation' => 'vertical', 'saved' => false];
    }

    /** @return list<string> */
    private function decodeCategories(mixed $value, string $report, string $fallback): array
    {
        try {
            $decoded = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value;

            return $this->validateCategories(is_array($decoded) ? $decoded : [], $report, $fallback);
        } catch (\Throwable) {
            return [$fallback];
        }
    }

    /** @param array<mixed> $categories @return list<string> */
    private function validateCategories(array $categories, string $report, string $field): array
    {
        $allowed = self::REPORT_CATEGORIES[$report];
        $normalized = array_values(array_unique(array_map('strval', $categories)));
        if ($normalized === [] || count($normalized) > 3 || array_diff($normalized, $allowed) !== []) {
            throw ValidationException::withMessages([
                $field => ['ประเภทข้อมูลไม่สอดคล้องกับแหล่งข้อมูลของรายงานนี้'],
            ]);
        }

        return $normalized;
    }
}
