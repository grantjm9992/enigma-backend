<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoutineCompletion;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class RoutineCompletionController extends Controller
{
    /**
     * Get all routine completions with filtering
     */
    public function index(Request $request): JsonResponse
    {
        $query = RoutineCompletion::with(['routine', 'category', 'completedBy', 'students']);

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('completed_at', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('completed_at', '<=', $request->end_date . ' 23:59:59');
        }

        // Routine filter
        if ($request->has('routine_id')) {
            $query->where('routine_id', $request->routine_id);
        }

        // Category filter
        if ($request->has('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // Trainer filter
        if ($request->has('completed_by')) {
            $query->where('completed_by', $request->completed_by);
        }

        // Session type filter
        if ($request->has('session_type')) {
            switch ($request->session_type) {
                case 'morning':
                    $query->morningSessions();
                    break;
                case 'afternoon':
                    $query->afternoonSessions();
                    break;
                case 'full_day':
                    $query->where('is_full_day_complete', true);
                    break;
            }
        }

        // Rating filter
        if ($request->has('min_rating')) {
            $query->where('rating', '>=', $request->min_rating);
        }

        // Student participation filter
        if ($request->has('student_id')) {
            $query->whereHas('students', function ($q) use ($request) {
                $q->where('users.id', $request->student_id);
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'completed_at');
        $sortDirection = $request->get('sort_direction', 'desc');
        $query->orderBy($sortBy, $sortDirection);

        // Pagination
        $perPage = $request->get('per_page', 20);
        $completions = $query->paginate($perPage);

        return response()->json([
            'completions' => $completions->items(),
            'pagination' => [
                'current_page' => $completions->currentPage(),
                'last_page' => $completions->lastPage(),
                'per_page' => $completions->perPage(),
                'total' => $completions->total(),
            ],
        ]);
    }

    /**
     * Get a specific completion with details
     */
    public function show(RoutineCompletion $completion): JsonResponse
    {
        $completion->load([
            'routine.blocks.exercises',
            'category',
            'completedBy',
            'students.studentProfile'
        ]);

        $completion->append(['duration_difference', 'efficiency_percentage']);

        return response()->json([
            'completion' => $completion,
        ]);
    }

    /**
     * Record a new routine completion
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'routine_id' => 'required|exists:routines,id',
            'completed_at' => 'required|date',
            'actual_duration' => 'required|integer|min:1',
            'rating' => 'nullable|integer|min:1|max:5',
            'morning_session' => 'boolean',
            'afternoon_session' => 'boolean',
            'is_full_day_complete' => 'boolean',
            'notes' => 'nullable|array',
            'block_completions' => 'nullable|array',
            'exercise_completions' => 'nullable|array',

            // Attendees
            'attendees' => 'required|array|min:1',
            'attendees.*.student_id' => 'required|exists:users,id',
            'attendees.*.participation_minutes' => 'required|integer|min:0',
            'attendees.*.performance_notes' => 'nullable|array',
            'attendees.*.completed_full_session' => 'boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $routine = Routine::find($data['routine_id']);

        // Verify students are actually students
        $studentIds = collect($data['attendees'])->pluck('student_id');
        $validStudents = User::whereIn('id', $studentIds)->students()->pluck('id');

        if ($studentIds->count() !== $validStudents->count()) {
            return response()->json([
                'message' => 'Some attendees are not valid students',
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Prepare completion data
            $completionData = [
                'routine_id' => $data['routine_id'],
                'routine_name' => $routine->name,
                'category_id' => $routine->categories->first()?->id,
                'category_name' => $routine->categories->first()?->name,
                'completed_at' => $data['completed_at'],
                'planned_duration' => $routine->total_duration,
                'actual_duration' => $data['actual_duration'],
                'rating' => $data['rating'] ?? null,
                'morning_session' => $data['morning_session'] ?? false,
                'afternoon_session' => $data['afternoon_session'] ?? false,
                'is_full_day_complete' => $data['is_full_day_complete'] ?? false,
                'notes' => $data['notes'] ?? null,
                'block_completions' => $data['block_completions'] ?? null,
                'exercise_completions' => $data['exercise_completions'] ?? null,
                'completed_by' => $request->user()->id,
            ];

            // Create completion record
            $completion = RoutineCompletion::create($completionData);

            // Add attendees
            foreach ($data['attendees'] as $attendeeData) {
                $completion->attendees()->create([
                    'student_id' => $attendeeData['student_id'],
                    'participation_minutes' => $attendeeData['participation_minutes'],
                    'performance_notes' => $attendeeData['performance_notes'] ?? null,
                    'completed_full_session' => $attendeeData['completed_full_session'] ?? true,
                ]);
            }

            // Update routine statistics
            $routine->incrementUsage();
            if ($data['rating']) {
                $routine->updateAverageRating();
            }

            // Update exercise usage counts
            if (isset($data['exercise_completions'])) {
                foreach ($data['exercise_completions'] as $exerciseCompletion) {
                    if (isset($exerciseCompletion['exercise_id'])) {
                        $exercise = \App\Models\Exercise::find($exerciseCompletion['exercise_id']);
                        if ($exercise) {
                            $exercise->incrementUsage();
                        }
                    }
                }
            }

            DB::commit();

            $completion->load(['routine', 'category', 'completedBy', 'students']);

            return response()->json([
                'message' => 'Routine completion recorded successfully',
                'completion' => $completion,
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to record completion',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a completion record
     */
    public function update(Request $request, RoutineCompletion $completion): JsonResponse
    {
        // Check permissions - only creator or admin can update
        $user = $request->user();
        if (!($user->isAdmin() || $completion->completed_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'actual_duration' => 'sometimes|integer|min:1',
            'rating' => 'nullable|integer|min:1|max:5',
            'notes' => 'nullable|array',
            'block_completions' => 'nullable|array',
            'exercise_completions' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $completion->update($validator->validated());

        // Update routine average rating if rating changed
        if ($request->has('rating')) {
            $completion->routine->updateAverageRating();
        }

        $completion->load(['routine', 'category', 'completedBy', 'students']);

        return response()->json([
            'message' => 'Completion updated successfully',
            'completion' => $completion,
        ]);
    }

    /**
     * Delete a completion record
     */
    public function destroy(RoutineCompletion $completion): JsonResponse
    {
        // Check permissions - only creator or admin can delete
        $user = request()->user();
        if (!($user->isAdmin() || $completion->completed_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $routine = $completion->routine;
        $completion->delete();

        // Update routine statistics
        $routine->updateAverageRating();

        return response()->json([
            'message' => 'Completion deleted successfully',
        ]);
    }

    /**
     * Get completion analytics
     */
    public function analytics(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        $query = RoutineCompletion::byDateRange($startDate, $endDate . ' 23:59:59');

        // Filter by trainer if specified
        if ($request->has('trainer_id')) {
            $query->where('completed_by', $request->trainer_id);
        }

        $completions = $query->with(['routine', 'category', 'students'])->get();

        // Daily statistics
        $dailyStats = $completions->groupBy(function ($completion) {
            return $completion->completed_at->format('Y-m-d');
        })->map(function ($dayCompletions, $date) {
            return [
                'date' => $date,
                'total_sessions' => $dayCompletions->count(),
                'total_minutes' => $dayCompletions->sum('actual_duration'),
                'morning_sessions' => $dayCompletions->where('morning_session', true)->count(),
                'afternoon_sessions' => $dayCompletions->where('afternoon_session', true)->count(),
                'full_day_sessions' => $dayCompletions->where('is_full_day_complete', true)->count(),
                'average_rating' => $dayCompletions->whereNotNull('rating')->avg('rating'),
                'unique_students' => $dayCompletions->flatMap->students->unique('id')->count(),
            ];
        })->values();

        // Work type distribution
        $workTypeDistribution = [];
        foreach ($completions as $completion) {
            if ($completion->exercise_completions) {
                foreach ($completion->exercise_completions as $exercise) {
                    $workType = $exercise['workType'] ?? 'unknown';
                    $duration = $exercise['duration'] ?? 0;
                    $workTypeDistribution[$workType] = ($workTypeDistribution[$workType] ?? 0) + $duration;
                }
            }
        }

        // Student participation
        $studentParticipation = $completions->flatMap(function ($completion) {
            return $completion->students->map(function ($student) use ($completion) {
                return [
                    'student_id' => $student->id,
                    'student_name' => $student->full_name,
                    'minutes' => $student->pivot->participation_minutes,
                    'session_date' => $completion->completed_at->format('Y-m-d'),
                ];
            });
        })->groupBy('student_id')->map(function ($sessions, $studentId) {
            $firstSession = $sessions->first();
            return [
                'student_id' => $studentId,
                'student_name' => $firstSession['student_name'],
                'total_minutes' => $sessions->sum('minutes'),
                'session_count' => $sessions->count(),
                'average_minutes_per_session' => round($sessions->avg('minutes'), 1),
                'last_session' => $sessions->max('session_date'),
            ];
        })->sortByDesc('total_minutes')->values();

        // Category performance
        $categoryStats = $completions->groupBy('category_id')->map(function ($categoryCompletions, $categoryId) {
            $category = $categoryCompletions->first()->category;
            return [
                'category_id' => $categoryId,
                'category_name' => $category?->name ?? 'Uncategorized',
                'session_count' => $categoryCompletions->count(),
                'total_minutes' => $categoryCompletions->sum('actual_duration'),
                'average_duration' => round($categoryCompletions->avg('actual_duration'), 1),
                'average_rating' => round($categoryCompletions->whereNotNull('rating')->avg('rating'), 2),
                'efficiency' => round($categoryCompletions->avg('efficiency_percentage'), 1),
            ];
        })->sortByDesc('session_count')->values();

        // Summary statistics
        $summary = [
            'total_sessions' => $completions->count(),
            'total_minutes' => $completions->sum('actual_duration'),
            'average_session_duration' => round($completions->avg('actual_duration'), 1),
            'average_rating' => round($completions->whereNotNull('rating')->avg('rating'), 2),
            'unique_routines' => $completions->unique('routine_id')->count(),
            'unique_students' => $completions->flatMap->students->unique('id')->count(),
            'morning_sessions' => $completions->where('morning_session', true)->count(),
            'afternoon_sessions' => $completions->where('afternoon_session', true)->count(),
            'full_day_sessions' => $completions->where('is_full_day_complete', true)->count(),
        ];

        return response()->json([
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'daily_stats' => $dailyStats,
            'work_type_distribution' => $workTypeDistribution,
            'student_participation' => $studentParticipation,
            'category_stats' => $categoryStats,
        ]);
    }

    /**
     * Get student performance analytics
     */
    public function studentAnalytics(Request $request, User $student): JsonResponse
    {
        if (!$student->isStudent()) {
            return response()->json([
                'message' => 'User is not a student',
            ], 422);
        }

        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        $completions = RoutineCompletion::byDateRange($startDate, $endDate . ' 23:59:59')
            ->whereHas('students', function ($query) use ($student) {
                $query->where('users.id', $student->id);
            })
            ->with(['routine', 'category'])
            ->get();

        // Get student participation details
        $participationData = $completions->map(function ($completion) use ($student) {
            $attendee = $completion->attendees()->where('student_id', $student->id)->first();
            return [
                'completion_id' => $completion->id,
                'routine_name' => $completion->routine_name,
                'category_name' => $completion->category_name,
                'completed_at' => $completion->completed_at->format('Y-m-d H:i'),
                'session_duration' => $completion->actual_duration,
                'participation_minutes' => $attendee->participation_minutes,
                'participation_percentage' => $attendee->participation_percentage,
                'completed_full_session' => $attendee->completed_full_session,
                'performance_notes' => $attendee->performance_notes,
                'session_rating' => $completion->rating,
            ];
        });

        // Weekly progress
        $weeklyProgress = $participationData->groupBy(function ($session) {
            return \Carbon\Carbon::parse($session['completed_at'])->startOfWeek()->format('Y-m-d');
        })->map(function ($weekSessions, $weekStart) {
            return [
                'week_start' => $weekStart,
                'session_count' => $weekSessions->count(),
                'total_minutes' => $weekSessions->sum('participation_minutes'),
                'average_participation' => round($weekSessions->avg('participation_percentage'), 1),
                'full_sessions' => $weekSessions->where('completed_full_session', true)->count(),
            ];
        })->sortBy('week_start')->values();

        // Category breakdown
        $categoryBreakdown = $participationData->groupBy('category_name')->map(function ($sessions, $category) {
            return [
                'category' => $category ?: 'Uncategorized',
                'session_count' => $sessions->count(),
                'total_minutes' => $sessions->sum('participation_minutes'),
                'average_participation' => round($sessions->avg('participation_percentage'), 1),
            ];
        })->sortByDesc('total_minutes')->values();

        // Summary
        $summary = [
            'total_sessions' => $participationData->count(),
            'total_minutes' => $participationData->sum('participation_minutes'),
            'average_session_duration' => round($participationData->avg('participation_minutes'), 1),
            'average_participation_rate' => round($participationData->avg('participation_percentage'), 1),
            'full_sessions_completed' => $participationData->where('completed_full_session', true)->count(),
            'consistency_rate' => $participationData->count() > 0 ?
                round(($participationData->where('completed_full_session', true)->count() / $participationData->count()) * 100, 1) : 0,
        ];

        return response()->json([
            'student' => [
                'id' => $student->id,
                'name' => $student->full_name,
                'email' => $student->email,
            ],
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'summary' => $summary,
            'weekly_progress' => $weeklyProgress,
            'category_breakdown' => $categoryBreakdown,
            'recent_sessions' => $participationData->sortByDesc('completed_at')->take(10)->values(),
        ]);
    }

    /**
     * Get completion statistics dashboard
     */
    public function dashboard(): JsonResponse
    {
        $user = request()->user();

        // Recent completions
        $recentCompletions = RoutineCompletion::with(['routine', 'category', 'students'])
            ->when(!$user->isAdmin(), function ($query) use ($user) {
                $query->where('completed_by', $user->id);
            })
            ->latest('completed_at')
            ->limit(5)
            ->get();

        // This week's stats
        $thisWeekCompletions = RoutineCompletion::whereBetween('completed_at', [
            now()->startOfWeek(), now()->endOfWeek()
        ])
            ->when(!$user->isAdmin(), function ($query) use ($user) {
                $query->where('completed_by', $user->id);
            })
            ->with(['students'])
            ->get();

        // Today's stats
        $todayCompletions = RoutineCompletion::whereDate('completed_at', today())
            ->when(!$user->isAdmin(), function ($query) use ($user) {
                $query->where('completed_by', $user->id);
            })
            ->with(['students'])
            ->get();

        $dashboard = [
            'today' => [
                'sessions' => $todayCompletions->count(),
                'minutes' => $todayCompletions->sum('actual_duration'),
                'students' => $todayCompletions->flatMap->students->unique('id')->count(),
            ],
            'this_week' => [
                'sessions' => $thisWeekCompletions->count(),
                'minutes' => $thisWeekCompletions->sum('actual_duration'),
                'students' => $thisWeekCompletions->flatMap->students->unique('id')->count(),
                'average_rating' => round($thisWeekCompletions->whereNotNull('rating')->avg('rating'), 2),
            ],
            'recent_completions' => $recentCompletions->map(function ($completion) {
                return [
                    'id' => $completion->id,
                    'routine_name' => $completion->routine_name,
                    'category_name' => $completion->category_name,
                    'completed_at' => $completion->completed_at->format('Y-m-d H:i'),
                    'duration' => $completion->actual_duration,
                    'rating' => $completion->rating,
                    'student_count' => $completion->students->count(),
                ];
            }),
        ];

        return response()->json($dashboard);
    }
}
