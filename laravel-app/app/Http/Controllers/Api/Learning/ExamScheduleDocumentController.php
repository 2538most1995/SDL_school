<?php

namespace App\Http\Controllers\Api\Learning;

use App\Domain\Students\Services\ExamScheduleExportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\Rule;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

final class ExamScheduleDocumentController extends Controller
{
    public function pdf(Request $request, ExamScheduleExportService $export): Response
    {
        $selection = $export->build($request->user(), $filters = $this->filters($request));
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
            // TH Sarabun New does not contain the zero-width space glyph that
            // mPDF's Thai dictionary line-breaker inserts between words.
            'useDictionaryLBR' => false,
        ]);
        $mpdf->SetTitle('ตารางสอบ');
        $mpdf->SetAuthor('SDL School');
        $mpdf->showImageErrors = false;
        $mpdf->shrink_tables_to_fit = 1;
        $mpdf->keep_table_proportions = true;
        // packTableData corrupts mixed-border table metadata in mPDF 8.3.1
        // and causes _fixTableBorders() to treat packed integers as arrays.
        $mpdf->packTableData = false;
        $mpdf->WriteHTML(view('pdf.exam-schedules', $selection)->render());
        $content = $mpdf->Output('', Destination::STRING_RETURN);
        $filename = $this->filename($filters, $selection['count']);

        return response($content, 200, [
            ...$this->privateHeaders(),
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Content-Length' => (string) strlen($content),
        ]);
    }

    /** @return array{scope: string, student?: string, group?: string, level?: int} */
    private function filters(Request $request): array
    {
        return $request->validate([
            'scope' => ['required', Rule::in(['student', 'group', 'level'])],
            'student' => ['nullable', 'string', 'max:64', 'required_if:scope,student'],
            'group' => ['nullable', 'string', 'max:120', 'required_if:scope,group'],
            'level' => ['nullable', 'integer', Rule::in([1, 2, 3]), 'required_if:scope,group,level'],
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
