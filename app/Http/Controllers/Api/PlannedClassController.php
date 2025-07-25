<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlannedClass;
use App\Models\Routine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class PlannedClassController extends Controller
{
    /**
     * Get all planned classes
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlannedClass::with(['routine', 'creator']);

        // Date range filter
        if ($request->has('start_date')) {
            $query->where('date', '>=', $request->start_date);
        }
        if ($request->has('end_date')) {
            $query->where('date', '<=', $request->end_date);
        }

        // Status filter
        if ($request->has('status') && $request->status !== 'all') {
            $query->byStatus($request->status);
        }

        // Class type filter
        if ($request->has('class_type') && $request->class_type !== 'all') {
            $query->byClassType($request->class_type);
        }

        // Routine filter
        if ($request->has('routine_id')) {
            $query->where('routine_id', $request->routine_id);
        }

        // Trainer filter
        if ($request->has('created_by')) {
            $query->where('created_by', $request->created_by);
        }

        // Today's classes
        if ($request->boolean('today_only')) {
            $query->today();
        }

        // Upcoming classes
        if ($request->boolean('upcoming_only')) {
            $query->upcoming();
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'date');
        $sortDirection = $request->get('sort_direction', 'asc');

        if ($sortBy === 'date') {
            $query->orderBy('date', $sortDirection)
                ->orderBy('start_time', $sortDirection);
        } else {
            $query->orderBy($sortBy, $sortDirection);
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $classes = $query->paginate($perPage);

        // Add computed attributes
        $classes->getCollection()->each(function ($class) {
            $class->append(['formatted_time', 'status_label', 'class_type_label']);
        });

        return response()->json([
            'classes' => $classes->items(),
            'pagination' => [
                'current_page' => $classes->currentPage(),
                'last_page' => $classes->lastPage(),
                'per_page' => $classes->perPage(),
                'total' => $classes->total(),
            ],
        ]);
    }

    /**
     * Get a specific planned class
     */
    public function show(PlannedClass $plannedClass): JsonResponse
    {
        $plannedClass->load(['routine.blocks.exercises', 'creator', 'completion']);
        $plannedClass->append(['formatted_time', 'status_label', 'class_type_label']);

        // Load target students information
        $targetStudents = [];
        if ($plannedClass->target_students) {
            $targetStudents = User::whereIn('id', $plannedClass->target_students)
                ->students()
                ->select('id', 'first_name', 'last_name', 'email')
                ->get();
        }

        return response()->json([
            'class' => $plannedClass,
            'target_students' => $targetStudents,
        ]);
    }

    /**
     * Create a new planned class
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'required|date_format:H:i',
            'end_time' => 'required|date_format:H:i|after:start_time',
            'routine_id' => 'nullable|exists:routines,id',
            'class_type' => 'required|in:morning,afternoon,evening,custom',
            'max_participants' => 'nullable|integer|min:1|max:50',
            'target_students' => 'nullable|array',
            'target_students.*' => 'exists:users,id',
            'materials_needed' => 'nullable|array',
            'notes' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Validate target students are actually students
        if (isset($data['target_students'])) {
            $validStudents = User::whereIn('id', $data['target_students'])->students()->pluck('id');
            if (collect($data['target_students'])->diff($validStudents)->isNotEmpty()) {
                return response()->json([
                    'message' => 'Some target students are not valid student users',
                ], 422);
            }
        }

        // Calculate duration
        $startTime = \Carbon\Carbon::createFromFormat('H:i', $data['start_time']);
        $endTime = \Carbon\Carbon::createFromFormat('H:i', $data['end_time']);
        $data['duration'] = $endTime->diffInMinutes($startTime);

        // Set creator
        $data['created_by'] = $request->user()->id;

        $plannedClass = PlannedClass::create($data);
        $plannedClass->load(['routine', 'creator']);

        return response()->json([
            'message' => 'Planned class created successfully',
            'class' => $plannedClass,
        ], 201);
    }

    /**
     * Update a planned class
     */
    public function update(Request $request, PlannedClass $plannedClass): JsonResponse
    {
        // Check permissions
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() || $plannedClass->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        // Can't update completed or cancelled classes
        if (in_array($plannedClass->status, ['completed', 'cancelled'])) {
            return response()->json([
                'message' => 'Cannot update completed or cancelled classes',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'date' => 'sometimes|date|after_or_equal:today',
            'start_time' => 'sometimes|date_format:H:i',
            'end_time' => 'sometimes|date_format:H:i|after:start_time',
            'routine_id' => 'nullable|exists:routines,id',
            'class_type' => 'sometimes|in:morning,afternoon,evening,custom',
            'max_participants' => 'nullable|integer|min:1|max:50',
            'target_students' => 'nullable|array',
            'target_students.*' => 'exists:users,id',
            'materials_needed' => 'nullable|array',
            'notes' => 'nullable|array',
            'status' => 'sometimes|in:planned,in_progress,completed,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Validate target students if provided
        if (isset($data['target_students'])) {
            $validStudents = User::whereIn('id', $data['target_students'])->students()->pluck('id');
            if (collect($data['target_students'])->diff($validStudents)->isNotEmpty()) {
                return response()->json([
                    'message' => 'Some target students are not valid student users',
                ], 422);
            }
        }

        // Recalculate duration if times changed
        if (isset($data['start_time']) || isset($data['end_time'])) {
            $startTime = \Carbon\Carbon::createFromFormat('H:i', $data['start_time'] ?? $plannedClass->start_time->format('H:i'));
            $endTime = \Carbon\Carbon::createFromFormat('H:i', $data['end_time'] ?? $plannedClass->end_time->format('H:i'));
            $data['duration'] = $endTime->diffInMinutes($startTime);
        }

        $plannedClass->update($data);
        $plannedClass->load(['routine', 'creator']);

        return response()->json([
            'message' => 'Planned class updated successfully',
            'class' => $plannedClass,
        ]);
    }

    /**
     * Delete a planned class
     */
    public function destroy(PlannedClass $plannedClass): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() || $plannedClass->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        // Can't delete completed classes
        if ($plannedClass->status === 'completed') {
            return response()->json([
                'message' => 'Cannot delete completed classes',
            ], 422);
        }

        $plannedClass->delete();

        return response()->json([
            'message' => 'Planned class deleted successfully',
        ]);
    }

    /**
     * Duplicate a planned class
     */
    public function duplicate(Request $request, PlannedClass $plannedClass): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'date' => 'required|date|after_or_equal:today',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'title' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Prepare duplicate data
        $duplicateData = $plannedClass->toArray();
        unset($duplicateData['id'], $duplicateData['created_at'], $duplicateData['updated_at'],
            $duplicateData['routine_completion_id'], $duplicateData['notion_page_id']);

        // Apply overrides
        $duplicateData['date'] = $data['date'];
        $duplicateData['start_time'] = $data['start_time'] ?? $duplicateData['start_time'];
        $duplicateData['end_time'] = $data['end_time'] ?? $duplicateData['end_time'];
        $duplicateData['title'] = $data['title'] ?? $duplicateData['title'] . ' (Copia)';
        $duplicateData['status'] = 'planned';
        $duplicateData['created_by'] = $request->user()->id;

        // Recalculate duration
        $startTime = \Carbon\Carbon::createFromFormat('H:i', $duplicateData['start_time']);
        $endTime = \Carbon\Carbon::createFromFormat('H:i', $duplicateData['end_time']);
        $duplicateData['duration'] = $endTime->diffInMinutes($startTime);

        $duplicate = PlannedClass::create($duplicateData);
        $duplicate->load(['routine', 'creator']);

        return response()->json([
            'message' => 'Class duplicated successfully',
            'class' => $duplicate,
        ], 201);
    }

    /**
     * Start a planned class (change status to in_progress)
     */
    public function start(PlannedClass $plannedClass): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer())) {
            return response()->json([
                'message' => 'Only trainers and admins can start classes',
            ], 403);
        }

        if ($plannedClass->status !== 'planned') {
            return response()->json([
                'message' => 'Only planned classes can be started',
            ], 422);
        }

        if (!$plannedClass->canBeStarted()) {
            return response()->json([
                'message' => 'Class cannot be started at this time',
            ], 422);
        }

        $plannedClass->update(['status' => 'in_progress']);

        return response()->json([
            'message' => 'Class started successfully',
            'class' => $plannedClass,
        ]);
    }

    /**
     * Cancel a planned class
     */
    public function cancel(PlannedClass $plannedClass): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() || $plannedClass->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        if (!in_array($plannedClass->status, ['planned', 'in_progress'])) {
            return response()->json([
                'message' => 'Only planned or in-progress classes can be cancelled',
            ], 422);
        }

        $plannedClass->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'Class cancelled successfully',
            'class' => $plannedClass,
        ]);
    }

    /**
     * Complete a class by linking it to a routine completion
     */
    public function complete(Request $request, PlannedClass $plannedClass): JsonResponse
    {
        // Check permissions
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer())) {
            return response()->json([
                'message' => 'Only trainers and admins can complete classes',
            ], 403);
        }

        if ($plannedClass->status === 'completed') {
            return response()->json([
                'message' => 'Class is already completed',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'routine_completion_id' => 'required|exists:routine_completions,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $plannedClass->update([
            'status' => 'completed',
            'routine_completion_id' => $request->routine_completion_id,
        ]);

        $plannedClass->load(['routine', 'creator', 'completion']);

        return response()->json([
            'message' => 'Class completed successfully',
            'class' => $plannedClass,
        ]);
    }

    /**
     * Get calendar view of planned classes
     */
    public function calendar(Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->get('end_date', now()->endOfMonth()->toDateString());

        $classes = PlannedClass::with(['routine', 'creator'])
            ->whereBetween('date', [$startDate, $endDate])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        // Group classes by date
        $calendar = $classes->groupBy(function ($class) {
            return $class->date->format('Y-m-d');
        })->map(function ($dayClasses, $date) {
            return [
                'date' => $date,
                'classes' => $dayClasses->map(function ($class) {
                    return [
                        'id' => $class->id,
                        'title' => $class->title,
                        'start_time' => $class->start_time->format('H:i'),
                        'end_time' => $class->end_time->format('H:i'),
                        'duration' => $class->duration,
                        'status' => $class->status,
                        'class_type' => $class->class_type,
                        'routine_name' => $class->routine?->name,
                        'max_participants' => $class->max_participants,
                        'target_student_count' => $class->target_students ? count($class->target_students) : 0,
                    ];
                })->values(),
                'class_count' => $dayClasses->count(),
                'total_duration' => $dayClasses->sum('duration'),
            ];
        });

        return response()->json([
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'calendar' => $calendar,
            'summary' => [
                'total_classes' => $classes->count(),
                'by_status' => $classes->groupBy('status')->map->count(),
                'by_class_type' => $classes->groupBy('class_type')->map->count(),
                'total_duration' => $classes->sum('duration'),
            ],
        ]);
    }

    /**
     * Get today's schedule
     */
    public function today(): JsonResponse
    {
        $todayClasses = PlannedClass::with(['routine', 'creator'])
            ->today()
            ->orderBy('start_time')
            ->get();

        $todayClasses->each(function ($class) {
            $class->append(['formatted_time', 'status_label', 'class_type_label']);
        });

        return response()->json([
            'date' => now()->format('Y-m-d'),
            'classes' => $todayClasses,
            'summary' => [
                'total_classes' => $todayClasses->count(),
                'completed' => $todayClasses->where('status', 'completed')->count(),
                'in_progress' => $todayClasses->where('status', 'in_progress')->count(),
                'upcoming' => $todayClasses->where('status', 'planned')->count(),
                'cancelled' => $todayClasses->where('status', 'cancelled')->count(),
            ],
        ]);
    }

    /**
     * Get upcoming classes
     */
    public function upcoming(Request $request): JsonResponse
    {
        $days = $request->get('days', 7); // Next 7 days by default

        $upcomingClasses = PlannedClass::with(['routine', 'creator'])
            ->where('status', 'planned')
            ->whereBetween('date', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $upcomingClasses->each(function ($class) {
            $class->append(['formatted_time', 'status_label', 'class_type_label']);
        });

        return response()->json([
            'period' => "{$days} days",
            'classes' => $upcomingClasses,
            'count' => $upcomingClasses->count(),
        ]);
    }

    /**
     * Get planned class statistics
     */
    public function statistics(): JsonResponse
    {
        $user = request()->user();

        $query = PlannedClass::query();
        if (!$user->isAdmin()) {
            $query->where('created_by', $user->id);
        }

        $stats = [
            'total_classes' => $query->count(),
            'by_status' => $query->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
            'by_class_type' => $query->selectRaw('class_type, COUNT(*) as count')
                ->groupBy('class_type')
                ->pluck('count', 'class_type')
                ->toArray(),
            'this_week' => $query->whereBetween('date', [now()->startOfWeek(), now()->endOfWeek()])->count(),
            'this_month' => $query->whereBetween('date', [now()->startOfMonth(), now()->endOfMonth()])->count(),
            'upcoming' => $query->upcoming()->count(),
            'today' => $query->today()->count(),
        ];

        return response()->json($stats);
    }
}
