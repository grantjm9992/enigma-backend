<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

class UserController extends Controller
{
    /**
     * Get all users (admin/trainer only)
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::with('studentProfile');

        // Filter by role
        if ($request->has('role') && $request->role !== 'all') {
            $query->where('role', $request->role);
        }

        // Filter by active status
        if ($request->has('active')) {
            $query->where('is_active', $request->boolean('active'));
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortDirection = $request->get('sort_direction', 'desc');

        if ($sortBy === 'name') {
            $query->orderBy('first_name', $sortDirection)
                ->orderBy('last_name', $sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        $users = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'users' => $users->items(),
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
            ],
        ]);
    }

    /**
     * Get a specific user
     */
    public function show(User $user): JsonResponse
    {
        $user->load('studentProfile');

        return response()->json([
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Create a new user (admin only)
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|unique:users',
            'role' => 'required|in:admin,trainer,student',
            'subscription_plan' => 'nullable|in:basic,premium,elite,trial',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::create([
            'email' => $request->email,
            'password' => Hash::make(str()->random(16)), // Random password, user will get temp password
            'role' => $request->role,
            'subscription_plan' => $request->subscription_plan,
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'phone' => $request->phone,
            'is_active' => true,
            'is_email_verified' => false,
        ]);

        // Generate temporary password
        $tempPassword = $user->generateTempPassword();

        // If user is a student, create student profile
        if ($user->isStudent()) {
            $user->studentProfile()->create([
                'level' => 'principiante',
                'strengths' => [],
                'weaknesses' => [],
                'pending_notes' => [],
            ]);
        }

        $user->load('studentProfile');

        return response()->json([
            'message' => 'User created successfully',
            'user' => $this->formatUser($user),
            'temp_password' => $tempPassword,
        ], 201);
    }

    /**
     * Update a user (admin/trainer only)
     */
    public function update(Request $request, User $user): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'sometimes|email|unique:users,email,' . $user->id,
            'role' => 'sometimes|in:admin,trainer,student',
            'subscription_plan' => 'nullable|in:basic,premium,elite,trial',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $oldRole = $user->role;
        $user->update($validator->validated());

        // If role changed to student, create student profile
        if ($user->role === 'student' && $oldRole !== 'student' && !$user->studentProfile) {
            $user->studentProfile()->create([
                'level' => 'principiante',
                'strengths' => [],
                'weaknesses' => [],
                'pending_notes' => [],
            ]);
        }

        // If role changed from student, delete student profile
        if ($user->role !== 'student' && $oldRole === 'student' && $user->studentProfile) {
            $user->studentProfile()->delete();
        }

        $user->load('studentProfile');

        return response()->json([
            'message' => 'User updated successfully',
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Delete a user (admin only)
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent deleting the current user
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Cannot delete your own account',
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'User deleted successfully',
        ]);
    }

    /**
     * Reset user password (admin only)
     */
    public function resetPassword(User $user): JsonResponse
    {
        $tempPassword = $user->generateTempPassword();

        return response()->json([
            'message' => 'Password reset successfully',
            'temp_password' => $tempPassword,
        ]);
    }

    /**
     * Toggle user active status (admin only)
     */
    public function toggleActive(User $user): JsonResponse
    {
        // Prevent deactivating the current user
        if ($user->id === auth()->id()) {
            return response()->json([
                'message' => 'Cannot deactivate your own account',
            ], 422);
        }

        $user->update(['is_active' => !$user->is_active]);

        return response()->json([
            'message' => $user->is_active ? 'User activated successfully' : 'User deactivated successfully',
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Get user statistics (admin/trainer only)
     */
    public function statistics(): JsonResponse
    {
        $stats = [
            'total_users' => User::count(),
            'active_users' => User::active()->count(),
            'by_role' => [
                'admin' => User::admins()->count(),
                'trainer' => User::trainers()->count(),
                'student' => User::students()->count(),
            ],
            'by_subscription' => [
                'basic' => User::where('subscription_plan', 'basic')->count(),
                'premium' => User::where('subscription_plan', 'premium')->count(),
                'elite' => User::where('subscription_plan', 'elite')->count(),
                'trial' => User::where('subscription_plan', 'trial')->count(),
            ],
            'recent_logins' => User::whereNotNull('last_login')
                ->where('last_login', '>=', now()->subDays(7))
                ->count(),
            'pending_email_verification' => User::where('is_email_verified', false)->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Format user data for API response
     */
    private function formatUser(User $user): array
    {
        $userData = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'first_name' => $user->first_name,
            'last_name' => $user->last_name,
            'full_name' => $user->full_name,
            'phone' => $user->phone,
            'profile_picture' => $user->profile_picture,
            'subscription_plan' => $user->subscription_plan,
            'is_active' => $user->is_active,
            'is_email_verified' => $user->is_email_verified,
            'last_login' => $user->last_login?->toISOString(),
            'temp_password' => $user->temp_password,
            'temp_password_expiry' => $user->temp_password_expiry?->toISOString(),
            'created_at' => $user->created_at->toISOString(),
            'updated_at' => $user->updated_at->toISOString(),
        ];

        // Include student profile if user is a student
        if ($user->isStudent() && $user->studentProfile) {
            $userData['student_profile'] = [
                'age' => $user->studentProfile->age,
                'height' => $user->studentProfile->height,
                'weight' => $user->studentProfile->weight,
                'last_weight_update' => $user->studentProfile->last_weight_update?->toISOString(),
                'level' => $user->studentProfile->level,
                'level_label' => $user->studentProfile->level_label,
                'strengths' => $user->studentProfile->strengths ?? [],
                'weaknesses' => $user->studentProfile->weaknesses ?? [],
                'notes' => $user->studentProfile->notes,
                'tactical_notes' => $user->studentProfile->tactical_notes,
                'last_tactical_notes_update' => $user->studentProfile->last_tactical_notes_update?->toISOString(),
                'pending_notes' => $user->studentProfile->pending_notes ?? [],
                'bmi' => $user->studentProfile->bmi,
            ];
        }

        return $userData;
    }
}
