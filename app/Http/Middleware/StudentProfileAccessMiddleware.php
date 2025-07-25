<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use App\Models\User;

class StudentProfileAccessMiddleware
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], 401);
        }

        // Get the student from the route parameter
        $student = $request->route('student');

        if (!$student || !($student instanceof User)) {
            return response()->json([
                'message' => 'Student not found.',
            ], 404);
        }

        // Admins and trainers can access any student profile
        if (in_array($user->role, ['admin', 'trainer'])) {
            return $next($request);
        }

        // Students can only access their own profile
        if ($user->role === 'student' && $user->id === $student->id) {
            return $next($request);
        }

        return response()->json([
            'message' => 'Insufficient permissions to access this student profile.',
        ], 403);
    }
}
