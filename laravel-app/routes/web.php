<?php

use App\Http\Controllers\Auth\LoginController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [LoginController::class, 'store'])
    ->middleware('throttle:10,1');
Route::post('/auth/logout', [LoginController::class, 'destroy'])
    ->middleware('auth');

Route::view('/login', 'app')->name('login');

$legacyRedirects = [
    'login.php' => '/login',
    'index.php' => '/app',
    'students.php' => '/students',
    'grades.php' => '/grades',
    'kpch.php' => '/kpch',
    'moral.php' => '/moral',
    'new_students_current_term.php' => '/reports/new-students',
    'graduated_students.php' => '/reports/graduates',
    'transferred_students.php' => '/reports/transfers',
    'registered_subjects.php' => '/reports/registered-subjects',
    'grades_above_2_stats.php' => '/reports/grade-threshold',
    'exam_attendance_stats.php' => '/reports/exam-attendance',
    'sena_learning.php' => '/learning',
    'assignments.php' => '/learning/assignments',
    'resources.php' => '/learning/resources',
    'lesson_plans.php' => '/learning/lesson-plans',
    'calendar.php' => '/learning/calendar',
    'exams.php' => '/learning/schedule',
    'scores.php' => '/learning/scores',
    'users.php' => '/admin/users',
    'exam_rooms.php' => '/admin/exam-rooms',
    'import.php' => '/admin/imports',
    'cleanup.php' => '/admin/data-maintenance',
    'profile.php' => '/settings/profile',
    'theme.php' => '/settings/appearance',
    'manage_login.php' => '/admin/branding',
];

foreach ($legacyRedirects as $legacyPath => $newPath) {
    Route::redirect($legacyPath, $newPath);
}

Route::get('/learning/schedule/view', [\App\Http\Controllers\Api\Learning\ExamScheduleDocumentController::class, 'html'])->name('learning.schedule.view');
Route::get('/learning/schedule/pdf', [\App\Http\Controllers\Api\Learning\ExamScheduleDocumentController::class, 'pdf'])->name('learning.schedule.pdf');

Route::view('/{path?}', 'app')->where('path', '^(?!api|sanctum|up|login|learning/schedule/view|learning/schedule/pdf$).*$');
