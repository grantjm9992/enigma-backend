<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\StudentController;

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
});
