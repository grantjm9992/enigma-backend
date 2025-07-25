<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class CategoryController extends Controller
{
    /**
     * Get all categories
     */
    public function index(Request $request): JsonResponse
    {
        $query = Category::query();

        // Filter by type
        if ($request->has('type') && $request->type !== 'all') {
            $query->byType($request->type);
        }

        // Filter by active status
        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->active();
            } else {
                $query->where('is_active', false);
            }
        } else {
            // Default to active only
            $query->active();
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        // Order
        $query->ordered();

        // Get with statistics if requested
        if ($request->boolean('with_stats')) {
            $categories = $query->get();
            $categories->each(function ($category) {
                $category->append(['usage_stats', 'total_minutes', 'average_rating']);
            });
        } else {
            $categories = $query->get();
        }

        return response()->json([
            'categories' => $categories,
            'total' => $categories->count(),
        ]);
    }

    /**
     * Get a specific category
     */
    public function show(Category $category): JsonResponse
    {
        $category->load(['exercises', 'routines']);
        $category->append(['usage_stats', 'total_minutes', 'average_rating', 'popular_exercises']);

        return response()->json([
            'category' => $category,
        ]);
    }

    /**
     * Create a new category
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:categories,name',
            'description' => 'nullable|string|max:1000',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'type' => 'required|in:phase,period,load-type,custom',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Get next sort order
        $nextSortOrder = Category::max('sort_order') + 1;

        $category = Category::create(array_merge($validator->validated(), [
            'sort_order' => $nextSortOrder,
        ]));

        return response()->json([
            'message' => 'Category created successfully',
            'category' => $category,
        ], 201);
    }

    /**
     * Update a category
     */
    public function update(Request $request, Category $category): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:categories,name,' . $category->id,
            'description' => 'nullable|string|max:1000',
            'color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'type' => 'sometimes|in:phase,period,load-type,custom',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $category->update($validator->validated());

        return response()->json([
            'message' => 'Category updated successfully',
            'category' => $category->fresh(),
        ]);
    }

    /**
     * Delete a category
     */
    public function destroy(Category $category): JsonResponse
    {
        // Check if category is being used
        if ($category->exercises()->count() > 0 || $category->routines()->count() > 0) {
            return response()->json([
                'message' => 'Cannot delete category that is being used by exercises or routines',
            ], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'Category deleted successfully',
        ]);
    }

    /**
     * Update sort order of categories
     */
    public function updateSortOrder(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'category_ids' => 'required|array',
            'category_ids.*' => 'exists:categories,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        Category::updateSortOrder($request->category_ids);

        return response()->json([
            'message' => 'Sort order updated successfully',
        ]);
    }

    /**
     * Get category statistics
     */
    public function statistics(): JsonResponse
    {
        $stats = Category::getDashboardStats();

        // Additional statistics
        $additionalStats = [
            'by_type' => Category::active()
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
            'usage_distribution' => Category::active()
                ->withCount(['exercises', 'routines', 'completions'])
                ->get()
                ->map(function ($category) {
                    return [
                        'id' => $category->id,
                        'name' => $category->name,
                        'exercises_count' => $category->exercises_count,
                        'routines_count' => $category->routines_count,
                        'completions_count' => $category->completions_count,
                        'total_usage' => $category->exercises_count +
                            $category->routines_count +
                            $category->completions_count,
                    ];
                })
                ->sortByDesc('total_usage')
                ->values(),
        ];

        return response()->json(array_merge($stats, $additionalStats));
    }

    /**
     * Get categories for dropdown/selection
     */
    public function options(Request $request): JsonResponse
    {
        $query = Category::active()->ordered();

        // Filter by type if specified
        if ($request->has('type')) {
            $query->byType($request->type);
        }

        $categories = $query->select('id', 'name', 'color', 'type')->get();

        return response()->json([
            'categories' => $categories,
        ]);
    }

    /**
     * Toggle category active status
     */
    public function toggleActive(Category $category): JsonResponse
    {
        $category->update(['is_active' => !$category->is_active]);

        return response()->json([
            'message' => $category->is_active ? 'Category activated' : 'Category deactivated',
            'category' => $category,
        ]);
    }

    /**
     * Get category usage analytics
     */
    public function analytics(Category $category, Request $request): JsonResponse
    {
        $startDate = $request->get('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->get('end_date', now()->toDateString());

        // Get completion trends over time
        $completionTrends = $category->completions()
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->selectRaw('DATE(completed_at) as date, COUNT(*) as sessions, SUM(actual_duration) as minutes')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Get student participation
        $studentParticipation = $category->completions()
            ->whereBetween('completed_at', [$startDate, $endDate])
            ->with(['students' => function ($query) {
                $query->select('users.id', 'first_name', 'last_name')
                    ->withPivot('participation_minutes');
            }])
            ->get()
            ->flatMap(function ($completion) {
                return $completion->students->map(function ($student) {
                    return [
                        'student_id' => $student->id,
                        'student_name' => $student->first_name . ' ' . $student->last_name,
                        'minutes' => $student->pivot->participation_minutes,
                    ];
                });
            })
            ->groupBy('student_id')
            ->map(function ($sessions, $studentId) {
                $firstSession = $sessions->first();
                return [
                    'student_id' => $studentId,
                    'student_name' => $firstSession['student_name'],
                    'total_minutes' => $sessions->sum('minutes'),
                    'session_count' => $sessions->count(),
                    'average_minutes' => round($sessions->avg('minutes'), 1),
                ];
            })
            ->sortByDesc('total_minutes')
            ->values();

        return response()->json([
            'category' => $category,
            'date_range' => [
                'start_date' => $startDate,
                'end_date' => $endDate,
            ],
            'trends' => $completionTrends,
            'student_participation' => $studentParticipation,
            'summary' => [
                'total_sessions' => $completionTrends->sum('sessions'),
                'total_minutes' => $completionTrends->sum('minutes'),
                'average_session_duration' => $completionTrends->avg('minutes'),
                'unique_students' => $studentParticipation->count(),
            ],
        ]);
    }
}
