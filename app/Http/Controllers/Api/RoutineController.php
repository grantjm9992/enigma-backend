<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Routine;
use App\Models\RoutineBlock;
use App\Models\Exercise;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class RoutineController extends Controller
{
    /**
     * Get all routines
     */
    public function index(Request $request): JsonResponse
    {
        $query = Routine::with(['categories', 'creator', 'blocks']);

        // Visibility filter - only show routines user can see
        $user = $request->user();
        $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('created_by', $user->id)
                ->orWhere(function ($subQ) use ($user) {
                    $subQ->where('visibility', 'shared')
                        ->when($user->isTrainer() || $user->isAdmin(), function ($userQ) {
                            $userQ->whereIn('created_by', function ($creatorQ) {
                                $creatorQ->select('id')->from('users')
                                    ->whereIn('role', ['trainer', 'admin']);
                            });
                        });
                });
        });

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

        // Difficulty filter
        if ($request->has('difficulty') && $request->difficulty !== 'all') {
            $query->byDifficulty($request->difficulty);
        }

        // Level filter (Spanish)
        if ($request->has('level') && $request->level !== 'all') {
            $query->byLevel($request->level);
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

        // Favorite filter
        if ($request->has('is_favorite')) {
            if ($request->boolean('is_favorite')) {
                $query->favorites();
            }
        }

        // Duration filter
        if ($request->has('min_duration')) {
            $query->where('total_duration', '>=', $request->min_duration);
        }
        if ($request->has('max_duration')) {
            $query->where('total_duration', '<=', $request->max_duration);
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
                $query->orderBy('total_duration', $sortDirection);
                break;
            case 'created':
                $query->orderBy('created_at', $sortDirection);
                break;
            case 'updated':
                $query->orderBy('updated_at', $sortDirection);
                break;
            default:
                $query->orderBy('name', $sortDirection);
        }

        // Pagination
        $perPage = $request->get('per_page', 15);
        $routines = $query->paginate($perPage);

        // Add computed attributes
        $routines->getCollection()->each(function ($routine) {
            $routine->append(['formatted_duration', 'stats']);
        });

        return response()->json([
            'routines' => $routines->items(),
            'pagination' => [
                'current_page' => $routines->currentPage(),
                'last_page' => $routines->lastPage(),
                'per_page' => $routines->perPage(),
                'total' => $routines->total(),
            ],
        ]);
    }

    /**
     * Get a specific routine with full details
     */
    public function show(Routine $routine): JsonResponse
    {
        // Check visibility
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() ||
            $routine->visibility === 'public' ||
            $routine->created_by === $user->id ||
            ($routine->visibility === 'shared' && ($user->isTrainer() || $user->isAdmin())))) {
            return response()->json([
                'message' => 'Routine not found',
            ], 404);
        }

        $routine->load([
            'categories',
            'creator',
            'blocks.exercises.categories',
            'completions' => function ($query) {
                $query->latest()->limit(5);
            }
        ]);

        $routine->append([
            'formatted_duration',
            'stats',
            'work_type_distribution',
            'upcoming_schedules'
        ]);

        return response()->json([
            'routine' => $routine,
        ]);
    }

    /**
     * Create a new routine
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:2000',
            'objective' => 'nullable|string|max:1000',
            'difficulty' => 'required|in:beginner,intermediate,advanced',
            'level' => 'required|in:principiante,intermedio,avanzado',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'materials' => 'nullable|array',
            'protection' => 'nullable|array',
            'is_template' => 'boolean',
            'is_favorite' => 'boolean',
            'visibility' => 'required|in:private,shared,public',
            'repeat_in_days' => 'nullable|integer|min:0|max:365',
            'scheduled_days' => 'nullable|array',
            'scheduled_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'trainer_notes' => 'nullable|string|max:2000',
            'category_ids' => 'nullable|array',
            'category_ids.*' => 'exists:categories,id',

            // Block structure
            'blocks' => 'required|array|min:1',
            'blocks.*.name' => 'required|string|max:255',
            'blocks.*.description' => 'nullable|string|max:1000',
            'blocks.*.color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'blocks.*.notes' => 'nullable|string|max:1000',
            'blocks.*.exercises' => 'required|array|min:1',
            'blocks.*.exercises.*.exercise_id' => 'required|exists:exercises,id',
            'blocks.*.exercises.*.duration_override' => 'nullable|integer|min:1',
            'blocks.*.exercises.*.exercise_notes' => 'nullable|array',
            'blocks.*.exercises.*.custom_timers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();
        $data['created_by'] = $request->user()->id;

        // Extract blocks and categories
        $blocks = $data['blocks'];
        $categoryIds = $data['category_ids'] ?? [];
        unset($data['blocks'], $data['category_ids']);

        // Calculate total duration
        $totalDuration = 0;
        foreach ($blocks as $blockData) {
            foreach ($blockData['exercises'] as $exerciseData) {
                $exercise = Exercise::find($exerciseData['exercise_id']);
                $duration = $exerciseData['duration_override'] ?? $exercise->total_duration;
                $totalDuration += $duration;
            }
        }
        $data['total_duration'] = $totalDuration;

        DB::beginTransaction();
        try {
            // Create routine
            $routine = Routine::create($data);

            // Attach categories
            if (!empty($categoryIds)) {
                $routine->categories()->attach($categoryIds);
            }

            // Create blocks with exercises
            foreach ($blocks as $index => $blockData) {
                $blockDuration = 0;
                $block = $routine->blocks()->create([
                    'name' => $blockData['name'],
                    'description' => $blockData['description'] ?? null,
                    'color' => $blockData['color'],
                    'notes' => $blockData['notes'] ?? null,
                    'sort_order' => $index + 1,
                    'duration' => 0, // Will be calculated
                ]);

                // Add exercises to block
                foreach ($blockData['exercises'] as $exerciseIndex => $exerciseData) {
                    $exercise = Exercise::find($exerciseData['exercise_id']);
                    $duration = $exerciseData['duration_override'] ?? $exercise->total_duration;
                    $blockDuration += $duration;

                    $block->exercises()->attach($exerciseData['exercise_id'], [
                        'sort_order' => $exerciseIndex + 1,
                        'duration_override' => $exerciseData['duration_override'] ?? null,
                        'exercise_notes' => $exerciseData['exercise_notes'] ?? null,
                        'custom_timers' => $exerciseData['custom_timers'] ?? null,
                    ]);

                    // Increment exercise usage
                    $exercise->incrementUsage();
                }

                // Update block duration
                $block->update(['duration' => $blockDuration]);
            }

            DB::commit();

            $routine->load(['categories', 'creator', 'blocks.exercises']);
            $routine->append(['formatted_duration', 'stats']);

            return response()->json([
                'message' => 'Routine created successfully',
                'routine' => $routine,
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to create routine',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update a routine
     */
    public function update(Request $request, Routine $routine): JsonResponse
    {
        // Check permissions
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() || $routine->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:2000',
            'objective' => 'nullable|string|max:1000',
            'difficulty' => 'sometimes|in:beginner,intermediate,advanced',
            'level' => 'sometimes|in:principiante,intermedio,avanzado',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:50',
            'materials' => 'nullable|array',
            'protection' => 'nullable|array',
            'is_template' => 'sometimes|boolean',
            'is_favorite' => 'sometimes|boolean',
            'visibility' => 'sometimes|in:private,shared,public',
            'is_active' => 'sometimes|boolean',
            'repeat_in_days' => 'nullable|integer|min:0|max:365',
            'scheduled_days' => 'nullable|array',
            'scheduled_days.*' => 'string|in:monday,tuesday,wednesday,thursday,friday,saturday,sunday',
            'trainer_notes' => 'nullable|string|max:2000',
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
            $routine->categories()->sync($data['category_ids']);
            unset($data['category_ids']);
        }

        $routine->update($data);
        $routine->load(['categories', 'creator', 'blocks.exercises']);

        return response()->json([
            'message' => 'Routine updated successfully',
            'routine' => $routine,
        ]);
    }

    /**
     * Delete a routine
     */
    public function destroy(Routine $routine): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() || $routine->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        // Check if routine has completions
        if ($routine->completions()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete routine that has completion records',
            ], 422);
        }

        $routine->delete();

        return response()->json([
            'message' => 'Routine deleted successfully',
        ]);
    }

    /**
     * Clone a routine
     */
    public function clone(Request $request, Routine $routine): JsonResponse
    {
        // Check visibility
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() ||
            $routine->visibility === 'public' ||
            $routine->created_by === $user->id ||
            ($routine->visibility === 'shared' && ($user->isTrainer() || $user->isAdmin())))) {
            return response()->json([
                'message' => 'Routine not found',
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

        $clonedRoutine = $routine->cloneRoutine($validator->validated());
        $clonedRoutine->load(['categories', 'creator', 'blocks.exercises']);

        return response()->json([
            'message' => 'Routine cloned successfully',
            'routine' => $clonedRoutine,
        ], 201);
    }

    /**
     * Update routine blocks structure
     */
    public function updateBlocks(Request $request, Routine $routine): JsonResponse
    {
        // Check permissions
        $user = $request->user();
        if (!($user->isAdmin() || $user->isTrainer() || $routine->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'blocks' => 'required|array|min:1',
            'blocks.*.id' => 'nullable|exists:routine_blocks,id',
            'blocks.*.name' => 'required|string|max:255',
            'blocks.*.description' => 'nullable|string|max:1000',
            'blocks.*.color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'blocks.*.notes' => 'nullable|string|max:1000',
            'blocks.*.exercises' => 'required|array|min:1',
            'blocks.*.exercises.*.exercise_id' => 'required|exists:exercises,id',
            'blocks.*.exercises.*.duration_override' => 'nullable|integer|min:1',
            'blocks.*.exercises.*.exercise_notes' => 'nullable|array',
            'blocks.*.exercises.*.custom_timers' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $blocksData = $validator->validated()['blocks'];

        DB::beginTransaction();
        try {
            // Get existing block IDs
            $existingBlockIds = $routine->blocks()->pluck('id')->toArray();
            $updatedBlockIds = collect($blocksData)->pluck('id')->filter()->values()->toArray();

            // Delete blocks that are no longer in the structure
            $blocksToDelete = array_diff($existingBlockIds, $updatedBlockIds);
            if (!empty($blocksToDelete)) {
                RoutineBlock::whereIn('id', $blocksToDelete)->delete();
            }

            $totalDuration = 0;

            // Update or create blocks
            foreach ($blocksData as $index => $blockData) {
                $blockDuration = 0;

                if (isset($blockData['id']) && $blockData['id']) {
                    // Update existing block
                    $block = RoutineBlock::find($blockData['id']);
                    $block->update([
                        'name' => $blockData['name'],
                        'description' => $blockData['description'] ?? null,
                        'color' => $blockData['color'],
                        'notes' => $blockData['notes'] ?? null,
                        'sort_order' => $index + 1,
                    ]);
                } else {
                    // Create new block
                    $block = $routine->blocks()->create([
                        'name' => $blockData['name'],
                        'description' => $blockData['description'] ?? null,
                        'color' => $blockData['color'],
                        'notes' => $blockData['notes'] ?? null,
                        'sort_order' => $index + 1,
                        'duration' => 0, // Will be calculated
                    ]);
                }

                // Update block exercises
                $exerciseData = [];
                foreach ($blockData['exercises'] as $exerciseIndex => $exercise) {
                    $exerciseModel = Exercise::find($exercise['exercise_id']);
                    $duration = $exercise['duration_override'] ?? $exerciseModel->total_duration;
                    $blockDuration += $duration;

                    $exerciseData[$exercise['exercise_id']] = [
                        'sort_order' => $exerciseIndex + 1,
                        'duration_override' => $exercise['duration_override'] ?? null,
                        'exercise_notes' => $exercise['exercise_notes'] ?? null,
                        'custom_timers' => $exercise['custom_timers'] ?? null,
                    ];
                }

                $block->exercises()->sync($exerciseData);
                $block->update(['duration' => $blockDuration]);
                $totalDuration += $blockDuration;
            }

            // Update routine total duration
            $routine->update(['total_duration' => $totalDuration]);

            DB::commit();

            $routine->load(['categories', 'creator', 'blocks.exercises']);

            return response()->json([
                'message' => 'Routine blocks updated successfully',
                'routine' => $routine,
            ]);

        } catch (\Exception $e) {
            DB::rollback();
            return response()->json([
                'message' => 'Failed to update routine blocks',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get routine statistics
     */
    public function statistics(): JsonResponse
    {
        $user = request()->user();

        $stats = [
            'total_routines' => Routine::where('created_by', $user->id)->active()->count(),
            'by_difficulty' => Routine::where('created_by', $user->id)
                ->active()
                ->selectRaw('difficulty, COUNT(*) as count')
                ->groupBy('difficulty')
                ->pluck('count', 'difficulty')
                ->toArray(),
            'by_level' => Routine::where('created_by', $user->id)
                ->active()
                ->selectRaw('level, COUNT(*) as count')
                ->groupBy('level')
                ->pluck('count', 'level')
                ->toArray(),
            'templates_count' => Routine::where('created_by', $user->id)->active()->templates()->count(),
            'favorites_count' => Routine::where('created_by', $user->id)->active()->favorites()->count(),
            'popular_routines' => Routine::getPopularRoutines(5),
            'recent_routines' => Routine::getRecentRoutines(7, 5),
            'total_completions' => $user->routineCompletions()->count(),
            'this_week_completions' => $user->routineCompletions()
                ->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])
                ->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Get routine options for dropdowns
     */
    public function options(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Routine::where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('created_by', $user->id)
                ->orWhere(function ($subQ) use ($user) {
                    $subQ->where('visibility', 'shared')
                        ->when($user->isTrainer() || $user->isAdmin(), function ($userQ) {
                            $userQ->whereIn('created_by', function ($creatorQ) {
                                $creatorQ->select('id')->from('users')
                                    ->whereIn('role', ['trainer', 'admin']);
                            });
                        });
                });
        })->active();

        // Filter by difficulty if specified
        if ($request->has('difficulty')) {
            $query->byDifficulty($request->difficulty);
        }

        // Filter by level if specified
        if ($request->has('level')) {
            $query->byLevel($request->level);
        }

        $routines = $query->select('id', 'name', 'total_duration', 'difficulty', 'level')
            ->orderBy('name')
            ->get();

        return response()->json([
            'routines' => $routines,
        ]);
    }

    /**
     * Toggle routine favorite status
     */
    public function toggleFavorite(Routine $routine): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() || $routine->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $routine->update(['is_favorite' => !$routine->is_favorite]);

        return response()->json([
            'message' => $routine->is_favorite ? 'Added to favorites' : 'Removed from favorites',
            'routine' => $routine,
        ]);
    }

    /**
     * Toggle routine active status
     */
    public function toggleActive(Routine $routine): JsonResponse
    {
        // Check permissions
        $user = request()->user();
        if (!($user->isAdmin() || $user->isTrainer() || $routine->created_by === $user->id)) {
            return response()->json([
                'message' => 'Insufficient permissions',
            ], 403);
        }

        $routine->update(['is_active' => !$routine->is_active]);

        return response()->json([
            'message' => $routine->is_active ? 'Routine activated' : 'Routine deactivated',
            'routine' => $routine,
        ]);
    }
}
