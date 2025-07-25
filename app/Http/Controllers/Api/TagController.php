<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class TagController extends Controller
{
    /**
     * Get all tags
     */
    public function index(Request $request): JsonResponse
    {
        $query = Tag::query();

        // Filter by active status
        if ($request->has('active')) {
            if ($request->boolean('active')) {
                $query->where('is_active', true);
            } else {
                $query->where('is_active', false);
            }
        } else {
            // Default to active only
            $query->where('is_active', true);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('name', 'like', "%{$search}%");
        }

        // Order by name
        $query->orderBy('name', 'asc');

        // Get with usage statistics if requested
        if ($request->boolean('with_stats')) {
            $tags = $query->get();
            $tags->each(function ($tag) {
                $tag->append(['usage_count', 'recent_usage']);
            });
        } else {
            $tags = $query->get();
        }

        return response()->json([
            'tags' => $tags,
            'total' => $tags->count(),
        ]);
    }

    /**
     * Get a specific tag
     */
    public function show(Tag $tag): JsonResponse
    {
        $tag->append(['usage_count', 'recent_usage']);

        return response()->json([
            'tag' => $tag,
        ]);
    }

    /**
     * Create a new tag
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:tags,name',
            'color' => 'required|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tag = Tag::create($validator->validated());

        return response()->json([
            'message' => 'Tag created successfully',
            'tag' => $tag,
        ], 201);
    }

    /**
     * Update a tag
     */
    public function update(Request $request, Tag $tag): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:tags,name,' . $tag->id,
            'color' => 'sometimes|string|regex:/^#[0-9A-Fa-f]{6}$/',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $tag->update($validator->validated());

        return response()->json([
            'message' => 'Tag updated successfully',
            'tag' => $tag->fresh(),
        ]);
    }

    /**
     * Delete a tag
     */
    public function destroy(Tag $tag): JsonResponse
    {
        // Check if tag is being used
        $isUsed = false;

        // Check routines
        if ($tag->routines()->count() > 0) {
            $isUsed = true;
        }

        // Check exercises
        if ($tag->exercises()->count() > 0) {
            $isUsed = true;
        }

        // Check planned classes
        if ($tag->plannedClasses()->count() > 0) {
            $isUsed = true;
        }

        if ($isUsed) {
            return response()->json([
                'message' => 'Cannot delete tag that is being used by routines, exercises, or planned classes',
            ], 422);
        }

        $tag->delete();

        return response()->json([
            'message' => 'Tag deleted successfully',
        ]);
    }

    /**
     * Get tag usage statistics
     */
    public function usageStatistics(): JsonResponse
    {
        $stats = Tag::with(['routines', 'exercises', 'plannedClasses'])
            ->get()
            ->map(function ($tag) {
                return [
                    'id' => $tag->id,
                    'name' => $tag->name,
                    'color' => $tag->color,
                    'routines_count' => $tag->routines->count(),
                    'exercises_count' => $tag->exercises->count(),
                    'planned_classes_count' => $tag->plannedClasses->count(),
                    'total_usage' => $tag->routines->count() + $tag->exercises->count() + $tag->plannedClasses->count(),
                ];
            })
            ->sortByDesc('total_usage')
            ->values();

        return response()->json([
            'statistics' => $stats,
            'summary' => [
                'total_tags' => $stats->count(),
                'most_used_tag' => $stats->first(),
                'average_usage' => $stats->avg('total_usage'),
            ],
        ]);
    }
}
