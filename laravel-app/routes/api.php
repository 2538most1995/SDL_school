<?php

use App\Http\Controllers\Api\Admin\BrandingController;
use App\Http\Controllers\Api\Admin\ExamRoomController;
use App\Http\Controllers\Api\Admin\ImportController;
use App\Http\Controllers\Api\Admin\ImportSafetyController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\Auth\DistrictOptionsController;
use App\Http\Controllers\Api\Auth\PublicBrandingController;
use App\Http\Controllers\Api\Learning\AssignmentController;
use App\Http\Controllers\Api\Learning\CalendarController;
use App\Http\Controllers\Api\Learning\ExamScheduleDocumentController;
use App\Http\Controllers\Api\Learning\LessonPlanController;
use App\Http\Controllers\Api\Learning\LearningContentController;
use App\Http\Controllers\Api\Learning\OverviewController as LearningOverviewController;
use App\Http\Controllers\Api\Learning\ResourceController;
use App\Http\Controllers\Api\Learning\ScheduleController;
use App\Http\Controllers\Api\Learning\ScoreController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\PortalDemoController;
use App\Http\Controllers\Api\Settings\AppearanceController;
use App\Http\Controllers\Api\Settings\NnetScheduleController;
use App\Http\Controllers\Api\Settings\ProfileController;
use App\Http\Controllers\Api\Students\CurrentStudentController;
use App\Http\Controllers\Api\Students\StudentDirectoryController;
use App\Http\Controllers\Api\Students\StudentGradesController;
use App\Http\Controllers\Api\Students\StudentExamScheduleController;
use App\Http\Controllers\Api\Students\StudentKpchController;
use App\Http\Controllers\Api\Students\StudentMoralController;
use App\Http\Controllers\Api\Students\StudentReportController;
use App\Http\Controllers\Api\Students\StudentSubjectsController;
use App\Http\Controllers\Api\SystemCatalogController;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/auth/districts', DistrictOptionsController::class)
        ->middleware('throttle:60,1');
    Route::get('/auth/branding', [PublicBrandingController::class, 'show'])->middleware('throttle:60,1');
    Route::get('/auth/branding/hero', [PublicBrandingController::class, 'hero'])->middleware('throttle:120,1');
    Route::get('/auth/branding/assets/{slot}', [PublicBrandingController::class, 'asset'])->whereIn('slot', ['logo', 'dashboard-hero'])->middleware('throttle:120,1');

    if ((bool) config('sena.demo_mode')) {
        Route::get('/portal-demo', PortalDemoController::class)
            ->middleware('throttle:60,1');
    }

    Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
        Route::get('/system/catalog', SystemCatalogController::class)
            ->middleware('throttle:60,1');
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
        Route::post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:10,1');
        Route::delete('/settings/profile/avatar', [ProfileController::class, 'destroyAvatar'])->middleware('throttle:10,1');
    });

    Route::middleware(['auth:sanctum', 'active', 'district', 'throttle:120,1'])->group(function (): void {
        Route::get('/portal', PortalController::class);
        Route::get('/learning', LearningOverviewController::class);
        Route::get('/learning/assignments', AssignmentController::class);
        Route::get('/learning/resources', ResourceController::class);
        Route::get('/learning/lesson-plans', LessonPlanController::class)->middleware('role:teacher,admin,super_admin');
        Route::get('/learning/calendar', CalendarController::class);
        Route::get('/learning/schedule', ScheduleController::class);
        Route::get('/learning/scores', ScoreController::class);
        Route::get('/learning/exam-schedule/pdf', [ExamScheduleDocumentController::class, 'pdf'])->middleware('throttle:8,1');
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
        Route::get('/reports/students/overview', [StudentReportController::class, 'overview']);
        Route::get('/reports/new-students', [StudentReportController::class, 'newStudents']);
        Route::get('/reports/graduates', [StudentReportController::class, 'graduates']);
        Route::get('/reports/expected-graduates', [StudentReportController::class, 'expectedGraduates']);
        Route::get('/reports/transfers', [StudentReportController::class, 'transfers']);
        Route::get('/reports/registered-subjects', [StudentReportController::class, 'registeredSubjects']);
        Route::get('/reports/students/grades-above-two', [StudentReportController::class, 'gradesAboveTwo']);
        Route::get('/reports/students/exam-attendance', [StudentReportController::class, 'examAttendance']);
        Route::get('/settings/profile', [ProfileController::class, 'show']);
        Route::patch('/settings/profile', [ProfileController::class, 'update'])->middleware('throttle:20,1');
        Route::patch('/settings/password', [ProfileController::class, 'updatePassword'])->middleware('throttle:10,1');
        Route::get('/settings/appearance', [AppearanceController::class, 'show']);
        Route::patch('/settings/appearance', [AppearanceController::class, 'update'])->middleware('throttle:20,1');
        Route::get('/settings/nnet-schedule', [NnetScheduleController::class, 'show']);
        Route::put('/settings/nnet-schedule', [NnetScheduleController::class, 'update'])->middleware('throttle:20,1');
    });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:admin,super_admin'])
        ->group(function (): void {
            Route::get('/admin/users', [UserController::class, 'index'])->middleware('throttle:120,1');
            Route::post('/admin/users', [UserController::class, 'store'])->middleware('throttle:20,1');
            Route::patch('/admin/users/{legacyUser}', [UserController::class, 'update'])->whereNumber('legacyUser')->middleware('throttle:30,1');
            Route::get('/admin/imports', [ImportController::class, 'index'])->middleware('throttle:120,1');
            Route::get('/admin/imports/jobs/{job}', [ImportController::class, 'status'])->whereUuid('job')->middleware('throttle:300,1');
            Route::post('/admin/imports', [ImportController::class, 'store'])->middleware('throttle:120,1');
            Route::delete('/admin/imports/{batch}', [ImportController::class, 'destroy'])
                ->where('batch', 'import_\\d{10}_[A-Za-z0-9]+')
                ->middleware('throttle:60,1');
            Route::get('/admin/imports/safety', ImportSafetyController::class)->middleware('throttle:120,1');
            Route::get('/admin/exam-rooms', [ExamRoomController::class, 'index'])->middleware('throttle:120,1');
            Route::post('/admin/exam-rooms', [ExamRoomController::class, 'store'])->middleware('throttle:30,1');
            Route::patch('/admin/exam-rooms/{examRoom}', [ExamRoomController::class, 'update'])->whereNumber('examRoom')->middleware('throttle:30,1');
            Route::delete('/admin/exam-rooms/{examRoom}', [ExamRoomController::class, 'destroy'])->whereNumber('examRoom')->middleware('throttle:20,1');
            Route::get('/admin/branding', [BrandingController::class, 'show'])->middleware('throttle:120,1');
            Route::patch('/admin/branding', [BrandingController::class, 'update'])->middleware('throttle:30,1');
            Route::post('/admin/branding/hero', [BrandingController::class, 'updateHero'])->middleware('throttle:10,1');
            Route::delete('/admin/branding/hero', [BrandingController::class, 'destroyHero'])->middleware('throttle:10,1');
            Route::post('/admin/branding/assets/{slot}', [BrandingController::class, 'updateAsset'])->whereIn('slot', ['logo', 'dashboard-hero'])->middleware('throttle:10,1');
            Route::delete('/admin/branding/assets/{slot}', [BrandingController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'dashboard-hero'])->middleware('throttle:10,1');
        });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:teacher,admin,super_admin', 'throttle:60,1'])
        ->group(function (): void {
            Route::post('/learning/{kind}', [LearningContentController::class, 'store'])->whereIn('kind', ['assignments', 'resources', 'lesson-plans', 'calendar']);
            Route::patch('/learning/{kind}/{content}', [LearningContentController::class, 'update'])->whereIn('kind', ['assignments', 'resources', 'lesson-plans', 'calendar'])->whereNumber('content');
            Route::delete('/learning/{kind}/{content}', [LearningContentController::class, 'destroy'])->whereIn('kind', ['assignments', 'resources', 'lesson-plans', 'calendar'])->whereNumber('content');
        });

    Route::middleware(['auth:sanctum', 'active', 'district', 'role:super_admin', 'throttle:30,1'])
        ->group(function (): void {
            Route::get('/super-admin/branding', [BrandingController::class, 'show']);
            Route::patch('/super-admin/branding', [BrandingController::class, 'update']);
            Route::post('/super-admin/branding/hero', [BrandingController::class, 'updateHero'])->middleware('throttle:10,1');
            Route::delete('/super-admin/branding/hero', [BrandingController::class, 'destroyHero'])->middleware('throttle:10,1');
            Route::post('/super-admin/branding/assets/{slot}', [BrandingController::class, 'updateAsset'])->whereIn('slot', ['logo', 'dashboard-hero'])->middleware('throttle:10,1');
            Route::delete('/super-admin/branding/assets/{slot}', [BrandingController::class, 'destroyAsset'])->whereIn('slot', ['logo', 'dashboard-hero'])->middleware('throttle:10,1');
        });
});
