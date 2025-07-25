<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StudentController;
use App\Http\Controllers\Api\RoutineController;
use App\Http\Controllers\Api\ExerciseController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\PlannedClassController;
use App\Http\Controllers\Api\RoutineCompletionController;
use App\Http\Controllers\Api\TagController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
*/

// Public routes
Route::prefix('auth')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/register', [AuthController::class, 'register']); // For initial setup
});

// Protected routes
Route::middleware('auth:sanctum')->group(function () {

    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::put('/profile', [AuthController::class, 'updateProfile']);
        Route::post('/change-password', [AuthController::class, 'changePassword']);

        // Admin only
        Route::middleware('role:admin')->group(function () {
            Route::post('/reset-password', [AuthController::class, 'resetPassword']);
        });
    });

    // User management routes (admin and trainer access)
    Route::middleware('role:admin,trainer')->group(function () {
        Route::apiResource('users', UserController::class)->except(['store']);
        Route::get('/users/statistics', [UserController::class, 'statistics']);
        Route::post('/users/{user}/reset-password', [UserController::class, 'resetPassword']);
        Route::post('/users/{user}/toggle-active', [UserController::class, 'toggleActive']);
    });

    // Admin only user creation
    Route::middleware('role:admin')->group(function () {
        Route::post('/users', [UserController::class, 'store']);
    });

    // Student management routes
    Route::prefix('students')->group(function () {
        // All authenticated users can view students (with appropriate filtering)
        Route::get('/', [StudentController::class, 'index']);
        Route::get('/statistics', [StudentController::class, 'statistics']);
        Route::get('/{student}', [StudentController::class, 'show']);

        // Students can only update their own profile, trainers/admins can update any
        Route::middleware('student.profile.access')->group(function () {
            Route::put('/{student}/profile', [StudentController::class, 'updateProfile']);
            Route::post('/{student}/weight', [StudentController::class, 'updateWeight']);
            Route::post('/{student}/pending-notes', [StudentController::class, 'addPendingNote']);
            Route::delete('/{student}/pending-notes/{noteIndex}', [StudentController::class, 'removePendingNote']);
        });

        // Only trainers and admins can update tactical notes
        Route::middleware('role:admin,trainer')->group(function () {
            Route::post('/{student}/tactical-notes', [StudentController::class, 'updateTacticalNotes']);
        });
    });

    // Category management routes
    Route::prefix('categories')->group(function () {
        Route::get('/', [CategoryController::class, 'index']);
        Route::get('/{category}', [CategoryController::class, 'show']);
        Route::post('/', [CategoryController::class, 'store']);
        Route::put('/{category}', [CategoryController::class, 'update']);
        Route::delete('/{category}', [CategoryController::class, 'destroy']);
        Route::post('/sort-order', [CategoryController::class, 'updateSortOrder']);
        Route::get('/statistics/usage', [CategoryController::class, 'usageStatistics']);
    });

    // Exercise management routes
    Route::prefix('exercises')->group(function () {
        Route::get('/', [ExerciseController::class, 'index']);
        Route::get('/{exercise}', [ExerciseController::class, 'show']);
        Route::post('/', [ExerciseController::class, 'store']);
        Route::put('/{exercise}', [ExerciseController::class, 'update']);
        Route::delete('/{exercise}', [ExerciseController::class, 'destroy']);
        Route::post('/{exercise}/clone', [ExerciseController::class, 'clone']);
        Route::post('/{exercise}/toggle-favorite', [ExerciseController::class, 'toggleFavorite']);
        Route::get('/statistics/usage', [ExerciseController::class, 'usageStatistics']);
    });

    // Routine management routes
    Route::prefix('routines')->group(function () {
        Route::get('/', [RoutineController::class, 'index']);
        Route::get('/{routine}', [RoutineController::class, 'show']);
        Route::post('/', [RoutineController::class, 'store']);
        Route::put('/{routine}', [RoutineController::class, 'update']);
        Route::delete('/{routine}', [RoutineController::class, 'destroy']);
        Route::post('/{routine}/clone', [RoutineController::class, 'clone']);
        Route::post('/{routine}/toggle-favorite', [RoutineController::class, 'toggleFavorite']);
        Route::post('/{routine}/toggle-active', [RoutineController::class, 'toggleActive']);
        Route::get('/statistics/usage', [RoutineController::class, 'usageStatistics']);
    });

    // Planned Class management routes
    Route::prefix('planned-classes')->group(function () {
        Route::get('/', [PlannedClassController::class, 'index']);
        Route::get('/{plannedClass}', [PlannedClassController::class, 'show']);
        Route::post('/', [PlannedClassController::class, 'store']);
        Route::put('/{plannedClass}', [PlannedClassController::class, 'update']);
        Route::delete('/{plannedClass}', [PlannedClassController::class, 'destroy']);
        Route::post('/{plannedClass}/duplicate', [PlannedClassController::class, 'duplicate']);
        Route::post('/{plannedClass}/complete', [PlannedClassController::class, 'markComplete']);
        Route::get('/calendar/view', [PlannedClassController::class, 'calendarView']);
        Route::get('/upcoming/list', [PlannedClassController::class, 'upcomingClasses']);
        Route::get('/statistics', [PlannedClassController::class, 'statistics']);
    });

    // Routine Completion routes
    Route::prefix('routine-completions')->group(function () {
        Route::get('/', [RoutineCompletionController::class, 'index']);
        Route::get('/{routineCompletion}', [RoutineCompletionController::class, 'show']);
        Route::post('/', [RoutineCompletionController::class, 'store']);
        Route::put('/{routineCompletion}', [RoutineCompletionController::class, 'update']);
        Route::delete('/{routineCompletion}', [RoutineCompletionController::class, 'destroy']);
        Route::get('/statistics/daily', [RoutineCompletionController::class, 'dailyStats']);
        Route::get('/statistics/student', [RoutineCompletionController::class, 'studentStats']);
        Route::get('/statistics/category', [RoutineCompletionController::class, 'categoryStats']);
        Route::post('/export', [RoutineCompletionController::class, 'exportData']);
    });

    // Tag management routes
    Route::prefix('tags')->group(function () {
        Route::get('/', [TagController::class, 'index']);
        Route::get('/{tag}', [TagController::class, 'show']);
        Route::post('/', [TagController::class, 'store']);
        Route::put('/{tag}', [TagController::class, 'update']);
        Route::delete('/{tag}', [TagController::class, 'destroy']);
        Route::get('/statistics/usage', [TagController::class, 'usageStatistics']);
    });

    // Dashboard and Analytics routes
    Route::prefix('dashboard')->group(function () {
        Route::get('/overview', [DashboardController::class, 'overview']);
        Route::get('/analytics', [DashboardController::class, 'analytics']);
        Route::get('/recent-activity', [DashboardController::class, 'recentActivity']);
    });
});
