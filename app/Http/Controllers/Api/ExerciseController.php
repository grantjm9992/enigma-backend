<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ExerciseController extends Controller
{
    /**
     * Get all exercises
     */
    public function index(Request $request): JsonResponse
    {
        $query = Exercise::with(['categories', 'creator']);

        // Visibility filter - only show exercises user can see
        $query->visibleTo($request->user());

        // Active filter
        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        } else {
            $query->active(); // Default to active only
        }

        // Work type filter
        if ($request->has('work_type') && $request->work_type !== 'all') {
            $query->byWorkType($request->work_type);
        }

        // Difficulty filter
        if ($request->has('difficulty') && $request->difficulty !== 'all') {
            $query->byDifficulty($request->difficulty);
        }

        // Intensity filter
        if ($request->has('intensity') && $request->intensity !== 'all') {
            $query->byIntensity($request->intensity);
        }

        // Category filter
        if ($request->has('category_id')) {
            $query->whereHas('categories', function ($q) use ($request) {
                $q->where('categories.id', $request->category_id);
            });
        }

        // Template filter
        if ($request->has('is_template')) {
            if ($request->boolean('is_template')) {
                $query->templates();
            } else {
                $query->where('is_template', false);
            }
        }

        // Multi-timer filter
        if ($request->has('is_multi_timer')) {
            if ($request->boolean('is_multi_timer')) {
                $query->multiTimer();
            } else {
                $query->regular();
            }
        }

        // Search
        if ($request->has('search')) {
            $query->search($request->search);
        }

        // Sorting
        $sortBy = $request->get('sort_by', 'name');
        $sortDirection = $request->get('sort_direction', 'asc');

        switch ($sortBy) {
            case 'usage':
                $query->orderBy('usage_count', $sortDirection);
                break;
            case 'rating':
                $query->orderBy('average_rating', $sortDirection);
                break;
            case 'duration':
                $query->orderBy('duration', $sortDirection);
                break;
            case 'created':
                $query->orderBy('created_at', $sortDirection);
                break;
            default:
                $query->orderBy('name', $sortDirection);
        }

        // Pagination
        $perPage = $request->get('per_page', 20);
        $exercises = $query->paginate($perPage);

        return response()->json([
            'exercises' => $exercises->items(),
            'pagination' => [
                'current_page' => $exercises->currentPage(),
                'last_page' => $exercises->lastPage(),
                'per_page' => $exercises->perPage(),
                'total' => $exercises->total(),
            ],
        ]);
    }

    /**
     * Get a specific exercise
     */
    public function show(Request $request, Exercise $exercise): JsonResponse
    {
        // Check visibility - admin/trainer can see all, users can see public/shared/own
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() ||
            $exercise->visibility === 'public' ||
            $exercise->created_by === $user->id ||
            ($exercise->visibility === 'shared' && ($user->isTrainer() || $user->isAdmin())))) {
            return response()->json([
                'message' => 'Exercise not found',
            ], 404);
        }

        $exercise->load(['categories', 'creator']);
        $exercise->append(['formatted_duration', 'total_duration', 'stats']);

        return response()->json([
            'exercise' => $exercise,
        ]);
    }

    /**
     * Create a new exercise
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'duration' => 'required|integer|min:1|max:300', // 1-300 minutes
            'intensity' => 'required|in:low,medium,high',
            'work_type' => 'required|in:strength,coordination,reaction,technique,cardio,flexibility,sparring,conditioning',
            'difficulty' => 'required|in:beginner,intermediate,advanced',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'materials' => 'nullable|array',
            'materials.*' => 'string|max:100',
            'protection' => 'nullable|array',
            'protection.*' => 'string|max:100',
            'instructions' => 'nullable|array',
            'instructions.*' => 'string|max:500',
            'video_url' => 'nullable|url|max:500',
            'image_url' => 'nullable|url|max:500',
            'is_multi_timer' => 'boolean',
            'timers' => 'nullable|array',
            'timers.*.name' => 'required_if:is_multi_timer,true|string|max:100',
            'timers.*.duration' => 'required_if:is_multi_timer,true|integer|min:1',
            'timers.*.repetitions' => 'required_if:is_multi_timer,true|integer|min:1',
            'timers.*.restBetween' => 'nullable|integer|min:0',
            'is_template' => 'boolean',
            'visibility' => 'required|in:private,shared,public',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $request->user()->id;

        // Remove category_ids from exercise data
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['category_ids']);

        $exercise = Exercise::create($data);

        // Attach categories
        if (!empty($categoryIds)) {
            $exercise->categories()->attach($categoryIds);
        }

        $exercise->load(['categories', 'creator']);

        return response()->json([
            'message' => 'Exercise created successfully',
            'exercise' => $exercise,
        ], 201);
    }

    /**
     * Update an exercise
     */
    public function update(Request $request, Exercise $exercise): JsonResponse
    {
        // Check permissions - only creator, admin, or trainer can update
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() || $exercise->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'duration' => 'sometimes|integer|min:1|max:300',
            'intensity' => 'sometimes|in:low,medium,high',
            'work_type' => 'sometimes|in:strength,coordination,reaction,technique,cardio,flexibility,sparring,conditioning',
            'difficulty' => 'sometimes|in:beginner,intermediate,advanced',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'materials' => 'nullable|array',
            'materials.*' => 'string|max:100',
            'protection' => 'nullable|array',
            'protection.*' => 'string|max:100',
            'instructions' => 'nullable|array',
            'instructions.*' => 'string|max:500',
            'video_url' => 'nullable|url|max:500',
            'image_url' => 'nullable|url|max:500',
            'is_multi_timer' => 'sometimes|boolean',
            'timers' => 'nullable|array',
            'timers.*.name' => 'required_if:is_multi_timer,true|string|max:100',
            'timers.*.duration' => 'required_if:is_multi_timer,true|integer|min:1',
            'timers.*.repetitions' => 'required_if:is_multi_timer,true|integer|min:1',
            'timers.*.restBetween' => 'nullable|integer|min:0',
            'is_template' => 'sometimes|boolean',
            'visibility' => 'sometimes|in:private,shared,public',
            'is_active' => 'sometimes|boolean',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Handle category updates
        if (isset($data['category_ids'])) {
            $exercise->categories()->sync($data['category_ids']);
            unset($data['category_ids']);
        }

        $exercise->update($data);
        $exercise->load(['categories', 'creator']);

        return response()->json([
            'message' => 'Exercise updated successfully',
            'exercise' => $exercise,
        ]);
    }

    /**
     * Delete an exercise
     */
    public function destroy(Request $request, Exercise $exercise): JsonResponse
    {
        // Check permissions - only creator, admin, or trainer can delete
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() || $exercise->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        // Check if exercise is being used in routines
        if ($exercise->routineBlocks()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete exercise that is being used in routines',
            ], 422);
        }

        $exercise->delete();

        return response()->json([
            'message' => 'Exercise deleted successfully',
        ]);
    }

    /**
     * Clone an exercise
     */
    public function clone(Request $request, Exercise $exercise): JsonResponse
    {
        // Check visibility - admin/trainer can see all, users can see public/shared/own
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() ||
            $exercise->visibility === 'public' ||
            $exercise->created_by === $user->id ||
            ($exercise->visibility === 'shared' && ($user->isTrainer() || $user->isAdmin())))) {
            return response()->json([
                'message' => 'Exercise not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'nullable|string|max:255',
            'visibility' => 'nullable|in:private,shared,public',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $clonedExercise = $exercise->cloneExercise($validator->validated());
        $clonedExercise->load(['categories', 'creator']);

        return response()->json([
            'message' => 'Exercise cloned successfully',
            'exercise' => $clonedExercise,
        ], 201);
    }

    /**
     * Get exercise statistics
     */
    public function statistics(): JsonResponse
    {
        $user = request()->user();

        $stats = [
            'total_exercises' => Exercise::visibleTo($user)->active()->count(),
            'by_work_type' => Exercise::visibleTo($user)
                ->active()
                ->selectRaw('work_type, COUNT(*) as count')
                ->groupBy('work_type')
                ->pluck('count', 'work_type')
                ->toArray(),
            'by_difficulty' => Exercise::visibleTo($user)
                ->active()
                ->selectRaw('difficulty, COUNT(*) as count')
                ->groupBy('difficulty')
                ->pluck('count', 'difficulty')
                ->toArray(),
            'by_intensity' => Exercise::visibleTo($user)
                ->active()
                ->selectRaw('intensity, COUNT(*) as count')
                ->groupBy('intensity')
                ->pluck('count', 'intensity')
                ->toArray(),
            'multi_timer_count' => Exercise::visibleTo($user)->active()->multiTimer()->count(),
            'template_count' => Exercise::visibleTo($user)->active()->templates()->count(),
            'popular_exercises' => Exercise::getPopularExercises(5),
            'recent_exercises' => Exercise::getRecentExercises(7, 5),
        ];

        return response()->json($stats);
    }

    /**
     * Get exercise options for dropdowns
     */
    public function options(Request $request): JsonResponse
    {
        $query = Exercise::visibleTo($request->user())->active();

        // Filter by work type if specified
        if ($request->has('work_type')) {
            $query->byWorkType($request->work_type);
        }

        // Filter by difficulty if specified
        if ($request->has('difficulty')) {
            $query->byDifficulty($request->difficulty);
        }

        $exercises = $query->select('id', 'name', 'duration', 'work_type', 'intensity')
            ->orderBy('name')
            ->get();

        return response()->json([
            'exercises' => $exercises,
        ]);
    }

    /**
     * Toggle exercise active status
     */
    public function toggleActive(Exercise $exercise): JsonResponse
    {
        // Check permissions
        if (!request()->user()->canUpdate($exercise)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $exercise->update(['is_active' => !$exercise->is_active]);

        return response()->json([
            'message' => $exercise->is_active ? 'Exercise activated' : 'Exercise deactivated',
            'exercise' => $exercise,
        ]);
    }

    /**
     * Rate an exercise
     */
    public function rate(Request $request, Exercise $exercise): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // In a real implementation, you'd store individual ratings
        // For now, we'll just update the average
        $currentRating = $exercise->average_rating ?? 0;
        $ratingCount = $exercise->usage_count > 0 ? $exercise->usage_count : 1;

        $newAverage = (($currentRating * $ratingCount) + $request->rating) / ($ratingCount + 1);

        $exercise->update(['average_rating' => round($newAverage, 2)]);

        return response()->json([
            'message' => 'Exercise rated successfully',
            'exercise' => $exercise->fresh(),
        ]);
    }
}
