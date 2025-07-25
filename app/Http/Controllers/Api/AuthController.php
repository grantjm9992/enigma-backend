<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login user
     */
    public function login(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();

        // Check if user exists and is active
        if (!$user || !$user->is_active) {
            return response()->json([
                'message' => 'Invalid credentials or inactive account',
            ], 401);
        }

        // Check password or temporary password
        $passwordValid = Hash::check($request->password, $user->password) ||
            ($user->temp_password && $request->password === $user->temp_password && $user->hasTempPassword());

        if (!$passwordValid) {
            return response()->json([
                'message' => 'Invalid credentials',
            ], 401);
        }

        // Update last login
        $user->update(['last_login' => now()]);

        // Create token
        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'message' => 'Login successful',
            'user' => $this->formatUser($user),
            'token' => $token,
            'is_first_login' => $user->hasTempPassword(),
        ]);
    }

    /**
     * Register a new user (admin only)
     */
    public function register(Request $request): JsonResponse
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

        // TODO: Send welcome email with temp password
        // $this->sendWelcomeEmail($user, $tempPassword);

        return response()->json([
            'message' => 'User created successfully',
            'user' => $this->formatUser($user),
            'temp_password' => $tempPassword,
        ], 201);
    }

    /**
     * Get current authenticated user
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user' => $this->formatUser($user),
            'is_first_login' => $user->hasTempPassword(),
        ]);
    }

    /**
     * Update user profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'profile_picture' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user->update($validator->validated());

        return response()->json([
            'message' => 'Profile updated successfully',
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'old_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Verify old password (could be temp password or regular password)
        $oldPasswordValid = Hash::check($request->old_password, $user->password) ||
            ($user->temp_password && $request->old_password === $user->temp_password && $user->hasTempPassword());

        if (!$oldPasswordValid) {
            return response()->json([
                'message' => 'Current password is incorrect',
            ], 422);
        }

        // Update password and clear temp password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        $user->clearTempPassword();

        return response()->json([
            'message' => 'Password changed successfully',
            'user' => $this->formatUser($user),
        ]);
    }

    /**
     * Logout user
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully',
        ]);
    }

    /**
     * Reset password (admin only)
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email|exists:users,email',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = User::where('email', $request->email)->first();
        $tempPassword = $user->generateTempPassword();

        // TODO: Send email with new temp password
        // $this->sendPasswordResetEmail($user, $tempPassword);

        return response()->json([
            'message' => 'Password reset successfully',
            'temp_password' => $tempPassword,
        ]);
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
