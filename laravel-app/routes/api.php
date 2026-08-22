<?php

use App\Http\Controllers\Api\Admin\BrandingController;
use App\Http\Controllers\Api\Admin\DistrictController;
use App\Http\Controllers\Api\Admin\ExamRoomController;
use App\Http\Controllers\Api\Admin\ImportController;
use App\Http\Controllers\Api\Admin\ImportSafetyController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\DistrictOptionsController;
use App\Http\Controllers\Api\Auth\PublicBrandingController;
use App\Http\Controllers\Api\Learning\AssignmentWorkflowController;
use App\Http\Controllers\Api\Learning\CalendarController;
use App\Http\Controllers\Api\Learning\ExamScheduleDocumentController;
use App\Http\Controllers\Api\Learning\LearningContentController;
use App\Http\Controllers\Api\Learning\LessonPlanController;
use App\Http\Controllers\Api\Learning\OverviewController as LearningOverviewController;
use App\Http\Controllers\Api\Learning\ResourceController;
use App\Http\Controllers\Api\Learning\ResourceFileController;
use App\Http\Controllers\Api\Learning\ScheduleController;
use App\Http\Controllers\Api\Learning\ScoreController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\PortalDemoController;
use App\Http\Controllers\Api\Settings\AppearanceController;
use App\Http\Controllers\Api\Settings\NnetScheduleController;
use App\Http\Controllers\Api\Settings\ProfileController;
use App\Http\Controllers\Api\Students\CurrentStudentController;
use App\Http\Controllers\Api\Students\StudentDirectoryController;
use App\Http\Controllers\Api\Students\StudentExamScheduleController;
use App\Http\Controllers\Api\Students\StudentGradesController;
use App\Http\Controllers\Api\Students\StudentKpchController;
use App\Http\Controllers\Api\Students\StudentMoralController;
use App\Http\Controllers\Api\Students\StudentReportController;
use App\Http\Controllers\Api\Students\StudentSocialProfileController;
use App\Http\Controllers\Api\Students\StudentSubjectsController;
use App\Http\Controllers\Api\SystemCatalogController;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/auth/districts', DistrictOptionsController::class);
    Route::get('/auth/branding', [PublicBrandingController::class, 'show']);
    Route::get('/auth/branding/hero', [PublicBrandingController::class, 'hero']);
    Route::get('/auth/branding/assets/{slot}', [PublicBrandingController::class, 'asset'])->whereIn('slot', ['logo', 'dashboard-hero']);
    Route::get('/learning/exam-schedule/view', [ExamScheduleDocumentController::class, 'html'])->name('api.learning.exam-schedule.view');
    Route::get('/learning/exam-schedule/pdf', [ExamScheduleDocumentController::class, 'pdf'])->name('api.learning.exam-schedule.pdf');

    if ((bool) config('sena.demo_mode')) {
        Route::get('/portal-demo', PortalDemoController::class);
    }

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/system/catalog', SystemCatalogController::class);
        Route::get('/me', function (Request $request): array {
            $user = $request->user();
            $districts = District::query()
                ->where('is_active', true)
                ->when($user->role !== 'super_admin', fn ($query) => $query->whereKey($user->district_id))
                ->orderBy('name')
                ->get(['id', 'name', 'code']);

            return ['data' => [
                'id' => $user->id,
                'name' => $user->displayName(),
                'avatar_url' => $user->avatarUrl(),
                'username' => $user->student_code ?: $user->username,
                'role' => $user->role,
                'district_id' => $user->district_id,
                'assigned_groups' => $user->assigned_groups ?? [],
                'auth_source' => $user->auth_source ?? 'local',
                'appearance' => [
                    'theme' => (string) ($user->theme ?: 'system'),
                    'colorScheme' => (string) ($user->color_scheme ?: 'blue'),
                    'fontSize' => (string) ($user->font_size ?: 'normal'),
                    'density' => (string) ($user->density ?: 'comfortable'),
                ],
                'districts' => $districts,
            ]];
        });
        Route::get('/settings/profile/avatar', [ProfileController::class, 'avatar']);
        Route::post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar']);
        Route::delete('/settings/profile/avatar', [ProfileController::class, 'destroyAvatar']);
        Route::get('/learning/calendar/{event}/image', [CalendarController::class, 'image'])->middleware('learning.schema')->whereNumber('event');
    });

    Route::middleware(['auth:sanctum', 'active', 'role:super_admin'])
        ->prefix('super-admin')
        ->group(function (): void {
            Route::get('/districts', [DistrictController::class, 'index']);
            Route::post('/districts', [DistrictController::class, 'store']);
        });

    Route::middleware(['auth:sanctum', 'active', 'district'])->group(function (): void {
        Route::get('/portal', PortalController::class);
        Route::get('/learning', LearningOverviewController::class)->middleware('learning.schema');
        Route::get('/learning/assignments', [AssignmentWorkflowController::class, 'index'])->middleware('learning.schema');
        Route::post('/learning/assignments/{assignment}/submit', [AssignmentWorkflowController::class, 'submit'])->middleware('learning.schema')->whereNumber('assignment');
        Route::get('/learning/assignments/{assignment}/submissions/{submission}/file', [AssignmentWorkflowController::class, 'file'])->middleware('learning.schema')->whereNumber(['assignment', 'submission']);
        Route::get('/learning/assignments/{assignment}/submissions/{submission}/files/{attachment}', [AssignmentWorkflowController::class, 'attachment'])->middleware('learning.schema')->whereNumber(['assignment', 'submission', 'attachment']);
        Route::get('/learning/assignments/{assignment}/material', [AssignmentWorkflowController::class, 'material'])->middleware('learning.schema')->whereNumber('assignment');
        Route::get('/learning/resources', ResourceController::class)->middleware('learning.schema');
        Route::get('/learning/resources/{resource}/file', ResourceFileController::class)->middleware('learning.schema')->whereNumber('resource');
        Route::get('/learning/lesson-plans', LessonPlanController::class)->middleware(['learning.schema', 'role:teacher,admin,super_admin']);
        Route::get('/learning/calendar', CalendarController::class)->middleware('learning.schema');
        Route::get('/learning/schedule', ScheduleController::class)->middleware('learning.schema');
        Route::get('/learning/scores', ScoreController::class)->middleware('learning.schema');
        Route::get('/learning/scores/workspace', [ScoreController::class, 'workspace'])->middleware(['learning.schema', 'role:teacher,admin,super_admin']);
        Route::get('/learning/scores/templates', [ScoreController::class, 'templates'])->middleware(['learning.schema', 'role:teacher,admin,super_admin']);
        Route::get('/learning/exam-schedule/signed-url', [ExamScheduleDocumentController::class, 'signedUrl']);
        Route::get('/my-learning', [CurrentStudentController::class, 'profile']);
        Route::get('/grades', [CurrentStudentController::class, 'grades']);
        Route::get('/kpch', [CurrentStudentController::class, 'kpch']);
        Route::get('/moral', [CurrentStudentController::class, 'moral']);
        Route::get('/students', [StudentDirectoryController::class, 'index']);
        Route::get('/students.php', [StudentDirectoryController::class, 'index']);
        Route::get('/students/{student}', [StudentDirectoryController::class, 'show']);
        Route::get('/students/{student}/grades', StudentGradesController::class);
        Route::get('/students/{student}/exam-schedule', StudentExamScheduleController::class);
        Route::get('/students/{student}/kpch', StudentKpchController::class);
        Route::get('/students/{student}/moral', StudentMoralController::class);
        Route::get('/students/{student}/subjects', StudentSubjectsController::class);
        Route::patch('/students/{student}/social', [StudentSocialProfileController::class, 'update']);
        Route::middleware('role:teacher,admin,super_admin')->group(function (): void {
            Route::get('/reports/students/overview', [StudentReportController::class, 'overview']);
            Route::get('/reports/new-students', [StudentReportController::class, 'newStudents']);
            Route::get('/reports/graduates', [StudentReportController::class, 'graduates']);
            Route::get('/reports/expected-graduates', [StudentReportController::class, 'expectedGraduates']);
            Route::get('/reports/transfers', [StudentReportController::class, 'transfers']);
            Route::get('/reports/registered-subjects', [StudentReportController::class, 'registeredSubjects']);
            Route::get('/reports/students/grades-above-two', [StudentReportController::class, 'gradesAboveTwo']);
            Route::get('/reports/students/exam-attendance', [StudentReportController::class, 'examAttendance']);
        });
        Route::get('/settings/profile', [ProfileController::class, 'show']);
        Route::patch('/settings/profile', [ProfileController::class, 'update']);
        Route::patch('/settings/password', [ProfileController::class, 'updatePassword']);
        Route::get('/settings/appearance', [AppearanceController::class, 'show']);
        Route::patch('/settings/appearance', [AppearanceController::class, 'update']);
        Route::get('/settings/nnet-schedule', [NnetScheduleController::class, 'show']);
        Route::put('/settings/nnet-schedule', [NnetScheduleController::class, 'update']);
    });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:admin,super_admin'])
        ->group(function (): void {
            Route::get('/admin/users', [UserController::class, 'index']);
            Route::post('/admin/users', [UserController::class, 'store']);
            Route::patch('/admin/users/{user}', [UserController::class, 'update'])->whereNumber('user');
            Route::get('/admin/imports', [ImportController::class, 'index']);
            Route::get('/admin/imports/jobs/{job}', [ImportController::class, 'status'])->whereUuid('job');
            Route::post('/admin/imports', [ImportController::class, 'store']);
            Route::delete('/admin/imports/{batch}', [ImportController::class, 'destroy'])
                ->where('batch', 'import_\\d{10}_[A-Za-z0-9]+');
            Route::get('/admin/imports/safety', ImportSafetyController::class);
            Route::post('/admin/exam-rooms', [ExamRoomController::class, 'store']);
            Route::post('/admin/exam-rooms/sync-from-schedule', [ExamRoomController::class, 'syncFromSchedule']);
            Route::post('/admin/exam-rooms/carry-forward', [ExamRoomController::class, 'syncFromSchedule']);
            Route::delete('/admin/exam-rooms/{examRoom}', [ExamRoomController::class, 'destroy'])->whereNumber('examRoom');
            Route::get('/admin/branding', [BrandingController::class, 'show']);
            Route::patch('/admin/branding', [BrandingController::class, 'update']);
            Route::post('/admin/branding/hero', [BrandingController::class, 'updateHero']);
            Route::delete('/admin/branding/hero', [BrandingController::class, 'destroyHero']);
            Route::post('/admin/branding/assets/{slot}', [BrandingController::class, 'updateAsset'])->whereIn('slot', ['logo', 'dashboard-hero']);
            Route::delete('/admin/branding/assets/{slot}', [BrandingController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'dashboard-hero']);
        });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:teacher,admin,super_admin'])
        ->group(function (): void {
            Route::get('/admin/exam-rooms', [ExamRoomController::class, 'index']);
            Route::patch('/admin/exam-rooms/bulk-update', [ExamRoomController::class, 'bulkUpdate']);
            Route::patch('/admin/exam-rooms/{examRoom}', [ExamRoomController::class, 'update'])->whereNumber('examRoom');
            Route::post('/learning/assignments', [AssignmentWorkflowController::class, 'store'])->middleware('learning.schema');
            Route::patch('/learning/assignments/{assignment}', [AssignmentWorkflowController::class, 'update'])->middleware('learning.schema')->whereNumber('assignment');
            Route::delete('/learning/assignments/{assignment}', [AssignmentWorkflowController::class, 'destroy'])->middleware('learning.schema')->whereNumber('assignment');
            Route::patch('/learning/assignments/{assignment}/submissions/{submission}', [AssignmentWorkflowController::class, 'review'])->middleware('learning.schema')->whereNumber(['assignment', 'submission']);
            Route::post('/learning/{kind}', [LearningContentController::class, 'store'])->middleware('learning.schema')->whereIn('kind', ['resources', 'lesson-plans', 'calendar']);
            Route::patch('/learning/{kind}/{content}', [LearningContentController::class, 'update'])->middleware('learning.schema')->whereIn('kind', ['resources', 'lesson-plans', 'calendar'])->whereNumber('content');
            Route::delete('/learning/{kind}/{content}', [LearningContentController::class, 'destroy'])->middleware('learning.schema')->whereIn('kind', ['resources', 'lesson-plans', 'calendar'])->whereNumber('content');
            Route::post('/learning/scores/scorebooks', [ScoreController::class, 'store'])->middleware('learning.schema');
            Route::post('/learning/scores/templates', [ScoreController::class, 'storeTemplate'])->middleware('learning.schema');
            Route::delete('/learning/scores/templates/{template}', [ScoreController::class, 'destroyTemplate'])->middleware('learning.schema')->whereNumber('template');
            Route::put('/learning/scores/scorebooks/{scorebook}/structure', [ScoreController::class, 'structure'])->middleware('learning.schema')->whereNumber('scorebook');
            Route::put('/learning/scores/scorebooks/{scorebook}/entries', [ScoreController::class, 'entries'])->middleware('learning.schema')->whereNumber('scorebook');
        });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:super_admin'])
        ->group(function (): void {
            Route::get('/super-admin/branding', [BrandingController::class, 'show']);
            Route::patch('/super-admin/branding', [BrandingController::class, 'update']);
            Route::post('/super-admin/branding/hero', [BrandingController::class, 'updateHero']);
            Route::delete('/super-admin/branding/hero', [BrandingController::class, 'destroyHero']);
            Route::post('/super-admin/branding/assets/{slot}', [BrandingController::class, 'updateAsset'])->whereIn('slot', ['logo', 'dashboard-hero']);
            Route::delete('/super-admin/branding/assets/{slot}', [BrandingController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'dashboard-hero']);
        });
});
