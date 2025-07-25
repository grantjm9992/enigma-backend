<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Category extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'description',
        'color',
        'type',
        'is_active',
        'sort_order',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Get the exercises that belong to this category
     */
    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'exercise_categories')
            ->withTimestamps();
    }

    /**
     * Get the routines that belong to this category
     */
    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(Routine::class, 'routine_categories')
            ->withTimestamps();
    }

    /**
     * Get routines completed in this category
     */
    public function completions()
    {
        return $this->hasMany(RoutineCompletion::class);
    }

    /**
     * Scope for active categories
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for categories by type
     */
    public function scopeByType($query, string $type)
    {
        return $query->where('type', $type);
    }

    /**
     * Scope for ordering by sort_order
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /**
     * Get category usage statistics
     */
    public function getUsageStatsAttribute(): array
    {
        return [
            'exercise_count' => $this->exercises()->count(),
            'routine_count' => $this->routines()->count(),
            'completion_count' => $this->completions()->count(),
            'last_used' => $this->completions()->latest('completed_at')->first()?->completed_at,
        ];
    }

    /**
     * Get total minutes spent in this category
     */
    public function getTotalMinutesAttribute(): int
    {
        return $this->completions()->sum('actual_duration');
    }

    /**
     * Get average rating for this category
     */
    public function getAverageRatingAttribute(): ?float
    {
        $completions = $this->completions()->whereNotNull('rating');

        if ($completions->count() === 0) {
            return null;
        }

        return round($completions->avg('rating'), 2);
    }

    /**
     * Get the most popular exercises in this category
     */
    public function getPopularExercisesAttribute()
    {
        return $this->exercises()
            ->orderBy('usage_count', 'desc')
            ->orderBy('average_rating', 'desc')
            ->limit(5)
            ->get();
    }

    /**
     * Update sort order for multiple categories
     */
    public static function updateSortOrder(array $categoryIds): void
    {
        foreach ($categoryIds as $index => $categoryId) {
            static::where('id', $categoryId)->update(['sort_order' => $index + 1]);
        }
    }

    /**
     * Get category statistics for dashboard
     */
    public static function getDashboardStats(): array
    {
        $categories = static::active()->with(['completions'])->get();

        return [
            'total_categories' => $categories->count(),
            'most_used' => $categories->sortByDesc('completion_count')->first(),
            'highest_rated' => $categories->whereNotNull('average_rating')->sortByDesc('average_rating')->first(),
            'recent_activity' => static::join('routine_completions', 'categories.id', '=', 'routine_completions.category_id')
                ->where('routine_completions.completed_at', '>=', now()->subDays(7))
                ->groupBy('categories.id')
                ->selectRaw('categories.*, COUNT(*) as recent_sessions')
                ->orderBy('recent_sessions', 'desc')
                ->first(),
        ];
    }
}
