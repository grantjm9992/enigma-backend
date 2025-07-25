<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\StudentProfile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class StudentController extends Controller
{
    /**
     * Get all students with their profiles
     */
    public function index(Request $request): JsonResponse
    {
        $query = User::students()->with('studentProfile');

        // Filter by level
        if ($request->has('level') && $request->level !== 'all') {
            $query->whereHas('studentProfile', function ($q) use ($request) {
                $q->where('level', $request->level);
            });
        }

        // Filter by age range
        if ($request->has('min_age') && $request->has('max_age')) {
            $query->whereHas('studentProfile', function ($q) use ($request) {
                $q->whereBetween('age', [$request->min_age, $request->max_age]);
            });
        }

        // Search by name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'first_name');
        $sortDirection = $request->get('sort_direction', 'asc');

        if ($sortBy === 'name') {
            $query->orderBy('first_name', $sortDirection)
                ->orderBy('last_name', $sortDirection);
        } elseif ($sortBy === 'level') {
            $query->join('student_profiles', 'users.id', '=', 'student_profiles.user_id')
                ->orderByRaw("FIELD(student_profiles.level, 'principiante', 'intermedio', 'avanzado', 'competidor', 'elite') " . $sortDirection);
        } elseif ($sortBy === 'age') {
            $query->join('student_profiles', 'users.id', '=', 'student_profiles.user_id')
                ->orderBy('student_profiles.age', $sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        $students = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'students' => collect($students->items())->map(function ($student) {
                return $this->formatStudent($student);
            }),
            'pagination' => [
                'current_page' => $students->currentPage(),
                'last_page' => $students->lastPage(),
                'per_page' => $students->perPage(),
                'total' => $students->total(),
            ],
        ]);
    }

    /**
     * Get a specific student with profile
     */
    public function show(User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $student->load('studentProfile');

        return response()->json([
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Update student profile
     */
    public function updateProfile(Request $request, User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'age' => 'nullable|integer|min:1|max:100',
            'height' => 'nullable|numeric|min:50|max:250',
            'weight' => 'nullable|numeric|min:20|max:200',
            'level' => 'sometimes|in:principiante,intermedio,avanzado,competidor,elite',
            'strengths' => 'nullable|array',
            'strengths.*' => 'string|max:255',
            'weaknesses' => 'nullable|array',
            'weaknesses.*' => 'string|max:255',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $profileData = $validator->validated();

        // If weight is being updated, track the date
        if (isset($profileData['weight'])) {
            $profileData['last_weight_update'] = now();
        }

        // Create profile if it doesn't exist
        if (!$student->studentProfile) {
            $student->studentProfile()->create(array_merge($profileData, [
                'strengths' => $profileData['strengths'] ?? [],
                'weaknesses' => $profileData['weaknesses'] ?? [],
                'pending_notes' => [],
            ]));
        } else {
            $student->studentProfile->update($profileData);
        }

        $student->load('studentProfile');

        return response()->json([
            'message' => 'Student profile updated successfully',
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Update weight specifically
     */
    public function updateWeight(Request $request, User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'weight' => 'required|numeric|min:20|max:200',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$student->studentProfile) {
            return response()->json([
                'message' => 'Student profile not found',
            ], 404);
        }

        $student->studentProfile->updateWeight($request->weight);
        $student->load('studentProfile');

        return response()->json([
            'message' => 'Weight updated successfully',
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Update tactical notes
     */
    public function updateTacticalNotes(Request $request, User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'tactical_notes' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$student->studentProfile) {
            return response()->json([
                'message' => 'Student profile not found',
            ], 404);
        }

        $student->studentProfile->updateTacticalNotes($request->tactical_notes);
        $student->load('studentProfile');

        return response()->json([
            'message' => 'Tactical notes updated successfully',
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Add pending note
     */
    public function addPendingNote(Request $request, User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'note' => 'required|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$student->studentProfile) {
            return response()->json([
                'message' => 'Student profile not found',
            ], 404);
        }

        $student->studentProfile->addPendingNote($request->note);
        $student->load('studentProfile');

        return response()->json([
            'message' => 'Pending note added successfully',
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Remove pending note
     */
    public function removePendingNote(Request $request, User $student, int $noteIndex): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        if (!$student->studentProfile) {
            return response()->json([
                'message' => 'Student profile not found',
            ], 404);
        }

        $student->studentProfile->removePendingNote($noteIndex);
        $student->load('studentProfile');

        return response()->json([
            'message' => 'Pending note removed successfully',
            'student' => $this->formatStudent($student),
        ]);
    }

    /**
     * Get student statistics
     */
    public function statistics(): JsonResponse
    {
        $totalStudents = User::students()->count();

        $stats = [
            'total_students' => $totalStudents,
            'active_students' => User::students()->active()->count(),
            'by_level' => [
                'principiante' => StudentProfile::byLevel('principiante')->count(),
                'intermedio' => StudentProfile::byLevel('intermedio')->count(),
                'avanzado' => StudentProfile::byLevel('avanzado')->count(),
                'competidor' => StudentProfile::byLevel('competidor')->count(),
                'elite' => StudentProfile::byLevel('elite')->count(),
            ],
            'by_age_group' => [
                'under_18' => StudentProfile::where('age', '<', 18)->count(),
                '18_to_25' => StudentProfile::whereBetween('age', [18, 25])->count(),
                '26_to_35' => StudentProfile::whereBetween('age', [26, 35])->count(),
                'over_35' => StudentProfile::where('age', '>', 35)->count(),
            ],
            'average_age' => StudentProfile::whereNotNull('age')->avg('age'),
            'with_profiles' => StudentProfile::count(),
            'pending_notes_count' => StudentProfile::whereRaw("JSON_LENGTH(pending_notes) > 0")->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Format student data for API response
     */
    private function formatStudent(User $student): array
    {
        $studentData = [
            'id' => $student->id,
            'email' => $student->email,
            'first_name' => $student->first_name,
            'last_name' => $student->last_name,
            'full_name' => $student->full_name,
            'phone' => $student->phone,
            'profile_picture' => $student->profile_picture,
            'subscription_plan' => $student->subscription_plan,
            'is_active' => $student->is_active,
            'last_login' => $student->last_login?->toISOString(),
            'created_at' => $student->created_at->toISOString(),
            'updated_at' => $student->updated_at->toISOString(),
        ];

        // Include student profile
        if ($student->studentProfile) {
            $studentData['profile'] = [
                'age' => $student->studentProfile->age,
                'height' => $student->studentProfile->height,
                'weight' => $student->studentProfile->weight,
                'last_weight_update' => $student->studentProfile->last_weight_update?->toISOString(),
                'level' => $student->studentProfile->level,
                'level_label' => $student->studentProfile->level_label,
                'strengths' => $student->studentProfile->strengths ?? [],
                'weaknesses' => $student->studentProfile->weaknesses ?? [],
                'notes' => $student->studentProfile->notes,
                'tactical_notes' => $student->studentProfile->tactical_notes,
                'last_tactical_notes_update' => $student->studentProfile->last_tactical_notes_update?->toISOString(),
                'pending_notes' => $student->studentProfile->pending_notes ?? [],
                'bmi' => $student->studentProfile->bmi,
            ];
        }

        return $studentData;
    }
}
