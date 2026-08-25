<?php

use App\Http\Controllers\CourseEnrollmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::livewire('platform', 'pages::platform.dashboard')
    ->middleware(['auth', 'verified'])
    ->name('platform.dashboard');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('courses/{course}/enroll', CourseEnrollmentController::class)
            ->name('courses.enroll');
        Route::livewire('courses/{course}', 'pages::courses.show')
            ->name('courses.show');
        Route::livewire('exams', 'pages::exams.index')
            ->name('exams.index');
        Route::livewire('exams/{exam}', 'pages::exams.show')
            ->name('exams.show');
        Route::livewire('exams/{exam}/attempts/{examAttempt}', 'pages::exams.take')
            ->name('exams.take');
        Route::livewire('exam-attempts/{examAttempt}/result', 'pages::exams.result')
            ->name('exams.result');
        Route::livewire('roadmaps', 'pages::roadmaps.index')
            ->name('roadmaps.index');
        Route::livewire('roadmaps/{roadmap}', 'pages::roadmaps.show')
            ->name('roadmaps.show');
        Route::get(
            'courses/{course}/modules/{courseModule}/lessons/{lesson}/resource',
            LessonResourceController::class,
        )->name('lessons.resource');

        Route::prefix('admin')
            ->name('admin.')
            ->middleware(EnsureTeamMembership::class.':admin')
            ->group(function () {
                Route::livewire('courses', 'pages::admin.courses.index')->name('courses.index');
                Route::livewire('courses/create', 'pages::admin.courses.create')->name('courses.create');
                Route::livewire(
                    'courses/{course}/assignments',
                    'pages::admin.courses.assignments',
                )->name('courses.assignments');
                Route::livewire('courses/{course}/exams', 'pages::admin.courses.exams')
                    ->name('courses.exams');
                Route::livewire('courses/{course}/edit', 'pages::admin.courses.edit')->name('courses.edit');
                Route::livewire(
                    'courses/{course}/modules/{courseModule}/lessons',
                    'pages::admin.courses.modules.lessons',
                )->name('courses.modules.lessons');
                Route::livewire('exams', 'pages::admin.exams.index')->name('exams.index');
                Route::livewire('exams/create', 'pages::admin.exams.create')->name('exams.create');
                Route::livewire('exams/{exam}/edit', 'pages::admin.exams.edit')->name('exams.edit');
                Route::livewire('exams/{exam}/attempts', 'pages::admin.exams.attempts')->name('exams.attempts');
                Route::livewire(
                    'exams/{exam}/attempts/{examAttempt}',
                    'pages::admin.exams.review',
                )->name('exams.review');
                Route::livewire('roadmaps', 'pages::admin.roadmaps.index')->name('roadmaps.index');
                Route::livewire('roadmaps/create', 'pages::admin.roadmaps.create')->name('roadmaps.create');
                Route::livewire('roadmaps/{roadmap}/edit', 'pages::admin.roadmaps.edit')->name('roadmaps.edit');
                Route::livewire(
                    'roadmaps/{roadmap}/assignments',
                    'pages::admin.roadmaps.assignments',
                )->name('roadmaps.assignments');
            });
    });

require __DIR__.'/settings.php';
