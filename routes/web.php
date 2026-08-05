<?php

use App\Http\Controllers\CourseEnrollmentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LessonResourceController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');
        Route::post('courses/{course}/enroll', CourseEnrollmentController::class)
            ->name('courses.enroll');
        Route::livewire('courses/{course}', 'pages::courses.show')
            ->name('courses.show');
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
                Route::livewire('courses/{course}/edit', 'pages::admin.courses.edit')->name('courses.edit');
                Route::livewire(
                    'courses/{course}/modules/{courseModule}/lessons',
                    'pages::admin.courses.modules.lessons',
                )->name('courses.modules.lessons');
            });
    });

require __DIR__.'/settings.php';
