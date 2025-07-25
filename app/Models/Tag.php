<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Tag extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected $appends = [];

    /**
     * Relationship with routines
     */
    public function routines(): BelongsToMany
    {
        return $this->belongsToMany(Routine::class, 'routine_tag');
    }

    /**
     * Relationship with exercises
     */
    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'exercise_tag');
    }

    /**
     * Relationship with planned classes
     */
    public function plannedClasses(): BelongsToMany
    {
        return $this->belongsToMany(PlannedClass::class, 'planned_class_tag');
    }

    /**
     * Get usage count across all entities
     */
    public function getUsageCountAttribute(): int
    {
        return $this->routines()->count() +
            $this->exercises()->count() +
            $this->plannedClasses()->count();
    }

    /**
     * Get recent usage (last 30 days)
     */
    public function getRecentUsageAttribute(): array
    {
        $thirtyDaysAgo = now()->subDays(30);

        return [
            'routines' => $this->routines()
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
            'exercises' => $this->exercises()
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
            'planned_classes' => $this->plannedClasses()
                ->where('created_at', '>=', $thirtyDaysAgo)
                ->count(),
        ];
    }

    /**
     * Scope: Active tags only
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: Search by name
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where('name', 'like', "%{$search}%");
    }

    /**
     * Scope: Order by usage
     */
    public function scopeOrderByUsage($query)
    {
        return $query->withCount(['routines', 'exercises', 'plannedClasses'])
            ->orderByDesc('routines_count')
            ->orderByDesc('exercises_count')
            ->orderByDesc('planned_classes_count');
    }
}
