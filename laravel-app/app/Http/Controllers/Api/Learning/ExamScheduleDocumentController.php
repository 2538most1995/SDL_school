<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Students\Services\ExamScheduleExportService;
use App\Http\Controllers\Controller;
use App\Models\District;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class ExamScheduleDocumentController extends Controller
{
    public function signedUrl(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $user = $request->user() ?? $request->user('sanctum');
        abort_if($user === null, 401, 'Unauthenticated.');

        $districtId = $user->district_id ?: $request->header('X-District-Id') ?: $request->query('district_id');

        $params = array_filter([
            'scope' => $filters['scope'],
            'student' => $filters['student'] ?? null,
            'group' => $filters['group'] ?? null,
            'level' => $filters['level'] ?? null,
            'disposition' => $request->query('disposition', 'inline'),
            'user_id' => (string) $user->id,
            'district_id' => $districtId ? (string) $districtId : null,
            'openExternalBrowser' => '1',
            'expires' => (string) now()->addHours(24)->timestamp,
        ], static fn ($v) => $v !== null && $v !== '');

        $signedPayload = $this->signedPayload($params);
        $signature = hash_hmac('sha256', 'exam-schedule:'.http_build_query($signedPayload), (string) config('app.key'));
        $params['signature'] = $signature;

        $viewUrl = url('/learning/schedule/view').'?'.http_build_query($params);
        $pdfUrl = url('/learning/schedule/pdf').'?'.http_build_query($params);

        return response()->json([
            'data' => [
                'url' => $viewUrl,
                'pdf_url' => $pdfUrl,
            ],
        ]);
    }

    public function html(Request $request, ExamScheduleExportService $export): Response
    {
        try {
            $user = $this->resolveUser($request);
            $this->resolveDistrict($request, $user);
            $selection = $export->build($user, $filters = $this->filters($request));

            $pdfParams = $request->query();
            $pdfParams['disposition'] = 'attachment';
            $selection['pdfDownloadUrl'] = url('/learning/schedule/pdf').'?'.http_build_query($pdfParams);

            return response()->view('print.exam-schedule-view', $selection, 200, [
                ...$this->privateHeaders(),
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        } catch (\Throwable $e) {
            $message = $e->getMessage() ?: 'ไม่สามารถแสดงตารางสอบได้';
            if ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() === 404) {
                $message = 'ไม่พบข้อมูลตารางสอบของนักศึกษา หรือยังไม่มีการประกาศตารางสอบในภาคเรียนนี้';
            }

            return response()->view('print.exam-schedule-error', [
                'message' => $message,
                'studentCode' => (string) $request->query('student', ''),
            ], 200, [
                ...$this->privateHeaders(),
                'Content-Type' => 'text/html; charset=UTF-8',
            ]);
        }
    }

    public function pdf(Request $request, ExamScheduleExportService $export): Response
    {
        $user = $this->resolveUser($request);
        $this->resolveDistrict($request, $user);
        $selection = $export->build($user, $filters = $this->filters($request));

        $fontDirectory = resource_path('fonts/thsarabunnew');
        $tempDirectory = storage_path('app/private/mpdf');
        File::ensureDirectoryExists($tempDirectory, 0750, true);
        $defaultFontDirectories = (new ConfigVariables)->getDefaults()['fontDir'];
        $defaultFontData = (new FontVariables)->getDefaults()['fontdata'];
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 12,
            'margin_bottom' => 12,
            'tempDir' => $tempDirectory,
            'fontDir' => [...$defaultFontDirectories, $fontDirectory],
            'fontdata' => $defaultFontData + [
                'thsarabunnew' => [
                    'R' => 'THSarabunNew.ttf',
                    'B' => 'THSarabunNew Bold.ttf',
                    'I' => 'THSarabunNew Italic.ttf',
                    'BI' => 'THSarabunNew BoldItalic.ttf',
                    'useOTL' => 0,
                    'useKPP' => 0,
                ],
            ],
            'default_font' => 'thsarabunnew',
            'default_font_size' => 16,
            'useDictionaryLBR' => false,
        ]);
        $mpdf->SetTitle('ตารางสอบ');
        $mpdf->SetAuthor('SDL School');
        $mpdf->showImageErrors = false;
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->keep_table_proportions = true;
        $mpdf->packTableData = false;
        $mpdf->WriteHTML(view('pdf.exam-schedules', $selection)->render());
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        $filename = $this->filename($filters, $selection['count']);
        $disposition = $request->query('disposition') === 'attachment' ? 'attachment' : 'inline';

        return response($content, 200, [
            ...$this->privateHeaders(),
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "{$disposition}; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($content),
        ]);
    }

    private function resolveUser(Request $request): User
    {
        $user = $request->user('sanctum');
        if ($user !== null) {
            return $user;
        }

        $valid = $this->verifySignature($request);
        abort_unless($valid, 401, 'Unauthenticated.');
        $userId = (int) $request->query('user_id');
        $user = User::query()->whereNull('disabled_at')->find($userId);
        abort_if($user === null, 401, 'Unauthenticated.');

        return $user;
    }

    private function verifySignature(Request $request): bool
    {
        $signature = (string) $request->query('signature');
        $expires = (int) $request->query('expires');
        if ($signature !== '' && $expires >= now()->timestamp) {
            $signedPayload = $this->signedPayload($request->query());
            $expected = hash_hmac('sha256', 'exam-schedule:'.http_build_query($signedPayload), (string) config('app.key'));
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return $request->hasValidSignature()
            || $request->hasValidRelativeSignature()
            || $request->hasValidSignature(false)
            || $request->hasValidRelativeSignature(false);
    }

    /** @param array<string, mixed> $query */
    private function signedPayload(array $query): array
    {
        $payload = [];
        foreach (['scope', 'student', 'group', 'level', 'disposition', 'user_id', 'district_id', 'expires', 'openExternalBrowser'] as $key) {
            if (isset($query[$key]) && (string) $query[$key] !== '') {
                $payload[$key] = (string) $query[$key];
            }
        }
        ksort($payload);

        return $payload;
    }

    private function resolveDistrict(Request $request, User $user): void
    {
        $requestedDistrict = $request->header('X-District-Id') ?: $request->query('district_id');
        if ($user->role === 'super_admin') {
            abort_if(blank($requestedDistrict), 422, 'กรุณาเลือกอำเภอก่อนเรียกดูข้อมูล');
            $districtId = filter_var($requestedDistrict, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($districtId, 422, 'รหัสอำเภอไม่ถูกต้อง');
        } else {
            abort_unless($user->district_id, 403, 'บัญชีนี้ยังไม่ได้กำหนดอำเภอ');
            $districtId = (int) $user->district_id;
            if (filled($requestedDistrict) && (int) $requestedDistrict !== $districtId) {
                abort(403, 'ไม่สามารถเข้าถึงข้อมูลของอำเภออื่นได้');
            }
        }

        $isActive = District::query()
            ->whereKey($districtId)
            ->where('is_active', true)
            ->exists();
        abort_unless($isActive, 404, 'ไม่พบอำเภอที่เปิดใช้งาน');

        $request->attributes->set('district_id', (int) $districtId);
        $user->setRelation('selectedDistrictContext', (int) $districtId);
    }

    /** @return array{scope: string, student?: string, group?: string, level?: int} */
    private function filters(Request $request): array
    {
        return $request->validate([
            'scope' => ['required', Rule::in(['student', 'group', 'level'])],
            'student' => ['nullable', 'string', 'max:64', 'required_if:scope,student'],
            'group' => ['nullable', 'string', 'max:120', 'required_if:scope,group'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3]), 'required_if:scope,group,level'],
            'disposition' => ['nullable', 'string', Rule::in(['inline', 'attachment'])],
        ]);
    }

    /** @return array<string, string> */
    private function privateHeaders(): array
    {
        return [
            'Cache-Control' => 'private, no-store, no-cache, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];
    }

    /** @param array<string, mixed> $filters */
    private function filename(array $filters, int $count): string
    {
        $scope = preg_replace('/[^a-z]/', '', (string) $filters['scope']) ?: 'schedule';

        return "exam-schedule-{$scope}-{$count}.pdf";
    }
}
