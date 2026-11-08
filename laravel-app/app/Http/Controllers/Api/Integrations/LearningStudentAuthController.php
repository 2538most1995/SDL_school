<?php

namespace App\Http\Controllers\Api\Integrations;

use App\Domain\Students\Models\Student;
use App\Domain\Students\Repositories\StudentAuthenticationRepository;
use App\Domain\Students\Repositories\StudentRepository;
use App\Http\Controllers\Controller;
use App\Models\StudentApiClient;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class LearningStudentAuthController extends Controller
{
    public function __invoke(Request $request, StudentRepository $students): JsonResponse
    {
        $client = $request->attributes->get('student_api_client');
        abort_unless($client instanceof StudentApiClient && $client->allows('sena-learning:authenticate'), 403);
        $input = $request->validate(['citizen_id' => ['required', 'string', 'regex:/^[0-9]{13}$/']]);
        $districtId = (int) $request->attributes->get('district_id');
        if ($students instanceof StudentAuthenticationRepository) {
            $identity = $students->findStudentForAuthentication($input['citizen_id'], $districtId);
        } else {
            $all = $students->students([$districtId]);
            $matches = array_values(array_filter($all, static fn (Student $student): bool => $student->districtId === $districtId && $student->citizenId !== null
                && hash_equals($student->citizenId, $input['citizen_id'])
            ));
            if (count($matches) !== 1 || $matches[0]->status !== 'studying') {
                return $this->respond(['message' => 'Student not found'], 404);
            }
            $student = $matches[0];
            $sameCode = array_filter($all, static fn (Student $candidate): bool => $candidate->districtId === $districtId && hash_equals($candidate->code, $student->code)
            );
            $identity = count($sameCode) === 1 ? [
                'student_id' => $student->code, 'full_name' => $student->fullName(),
                'group_code' => $student->groupCode, 'class_name' => $student->groupName,
                'district_id' => $student->districtId, 'district_name' => $student->districtName,
                'level' => $student->level, 'level_name' => $student->levelLabel,
            ] : null;
        }
        if ($identity === null || empty($identity['student_id']) || User::query()
            ->where('role', 'student')->where('district_id', $districtId)
            ->where('student_code', $identity['student_id'])->whereNotNull('disabled_at')->exists()) {
            return $this->respond(['message' => 'Student not found'], 404);
        }

        return $this->respond(['data' => $identity]);
    }

    private function respond(array $body, int $status = 200): JsonResponse
    {
        return response()->json($body, $status)->header('Cache-Control', 'private, no-store')
            ->header('Vary', 'Authorization, X-Student-Data-Token');
    }
}
