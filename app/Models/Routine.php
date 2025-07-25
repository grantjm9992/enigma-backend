<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Routine extends Model
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
        'objective',
        'total_duration',
        'difficulty',
        'level',
        'tags',
        'materials',
        'protection',
        'is_template',
        'is_favorite',
        'visibility',
        'is_active',
        'repeat_in_days',
        'scheduled_days',
        'trainer_notes',
        'created_by',
        'usage_count',
        'average_rating',
        'notion_page_id',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tags' => 'array',
            'materials' => 'array',
            'protection' => 'array',
            'scheduled_days' => 'array',
            'is_template' => 'boolean',
            'is_favorite' => 'boolean',
            'is_active' => 'boolean',
            'total_duration' => 'integer',
            'repeat_in_days' => 'integer',
            'usage_count' => 'integer',
            'average_rating' => 'decimal:2',
        ];
    }

    /**
     * Get the user who created this routine
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the categories that belong to this routine
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'routine_categories')
            ->withTimestamps();
    }

    /**
     * Get the blocks that belong to this routine
     */
    public function blocks(): HasMany
    {
        return $this->hasMany(RoutineBlock::class)->orderBy('sort_order');
    }

    /**
     * Get the completions for this routine
     */
    public function completions(): HasMany
    {
        return $this->hasMany(RoutineCompletion::class);
    }

    /**
     * Get the planned classes using this routine
     */
    public function plannedClasses(): HasMany
    {
        return $this->hasMany(PlannedClass::class);
    }

    /**
     * Get all exercises used in this routine (through blocks)
     */
    public function exercises()
    {
        return Exercise::whereHas('routineBlocks', function ($query) {
            $query->whereIn('routine_block_id', $this->blocks()->pluck('id'));
        });
    }

    /**
     * Scope for active routines
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for template routines
     */
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    /**
     * Scope for favorite routines
     */
    public function scopeFavorites($query)
    {
        return $query->where('is_favorite', true);
    }

    /**
     * Scope for routines by difficulty
     */
    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    /**
     * Scope for routines by level (Spanish)
     */
    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope for routines visible to a user
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('created_by', $user->id)
                ->orWhere(function ($subQ) use ($user) {
                    $subQ->where('visibility', 'shared')
                        ->whereIn('created_by', function ($userQ) use ($user) {
                            if ($user->isTrainer() || $user->isAdmin()) {
                                $userQ->select('id')->from('users')
                                    ->whereIn('role', ['trainer', 'admin']);
                            } else {
                                $userQ->select('id')->from('users')->where('id', $user->id);
                            }
                        });
                });
        });
    }

    /**
     * Search routines by name, description, or tags
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhere('objective', 'like', "%{$search}%")
                ->orWhereJsonContains('tags', $search);
        });
    }

    /**
     * Get formatted duration for display
     */
    public function getFormattedDurationAttribute(): string
    {
        return $this->total_duration >= 60
            ? sprintf('%dh %dm', intval($this->total_duration / 60), $this->total_duration % 60)
            : "{$this->total_duration}m";
    }

    /**
     * Get difficulty label in Spanish
     */
    public function getDifficultyLabelAttribute(): string
    {
        $labels = [
            'beginner' => 'Principiante',
            'intermediate' => 'Intermedio',
            'advanced' => 'Avanzado',
        ];

        return $labels[$this->difficulty] ?? $this->difficulty;
    }

    /**
     * Get level label
     */
    public function getLevelLabelAttribute(): string
    {
        $labels = [
            'principiante' => 'Principiante',
            'intermedio' => 'Intermedio',
            'avanzado' => 'Avanzado',
        ];

        return $labels[$this->level] ?? $this->level;
    }

    /**
     * Get visibility label
     */
    public function getVisibilityLabelAttribute(): string
    {
        $labels = [
            'private' => 'Privada',
            'shared' => 'Compartida',
            'public' => 'Pública',
        ];

        return $labels[$this->visibility] ?? $this->visibility;
    }

    /**
     * Calculate total duration from blocks
     */
    public function calculateTotalDuration(): int
    {
        return $this->blocks()->sum('duration');
    }

    /**
     * Update total duration from blocks
     */
    public function updateTotalDuration(): void
    {
        $this->update(['total_duration' => $this->calculateTotalDuration()]);
    }

    /**
     * Increment usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Update average rating from completions
     */
    public function updateAverageRating(): void
    {
        $ratings = $this->completions()->whereNotNull('rating')->pluck('rating');

        $this->update([
            'average_rating' => $ratings->count() > 0 ? $ratings->avg() : null
        ]);
    }

    /**
     * Clone routine with all blocks and exercises
     */
    public function cloneRoutine(array $modifications = []): Routine
    {
        $attributes = $this->toArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at'], $attributes['notion_page_id']);

        $attributes['name'] = ($modifications['name'] ?? $attributes['name']) . ' (Copia)';
        $attributes['is_template'] = false;
        $attributes['is_favorite'] = false;
        $attributes['usage_count'] = 0;
        $attributes['average_rating'] = null;
        $attributes['created_by'] = auth()->id();

        $clone = static::create(array_merge($attributes, $modifications));

        // Clone category relationships
        $clone->categories()->attach($this->categories->pluck('id'));

        // Clone blocks and their exercises
        foreach ($this->blocks as $block) {
            $clonedBlock = $clone->blocks()->create([
                'name' => $block->name,
                'description' => $block->description,
                'color' => $block->color,
                'notes' => $block->notes,
                'sort_order' => $block->sort_order,
                'duration' => $block->duration,
            ]);

            // Clone block exercises with pivot data
            foreach ($block->exercises as $exercise) {
                $clonedBlock->exercises()->attach($exercise->id, [
                    'sort_order' => $exercise->pivot->sort_order,
                    'duration_override' => $exercise->pivot->duration_override,
                    'exercise_notes' => $exercise->pivot->exercise_notes,
                    'custom_timers' => $exercise->pivot->custom_timers,
                ]);
            }
        }

        return $clone;
    }

    /**
     * Get routine statistics
     */
    public function getStatsAttribute(): array
    {
        $completions = $this->completions();

        return [
            'usage_count' => $this->usage_count,
            'completion_count' => $completions->count(),
            'average_rating' => $this->average_rating,
            'total_minutes_completed' => $completions->sum('actual_duration'),
            'average_completion_time' => $completions->avg('actual_duration'),
            'last_completed' => $completions->latest('completed_at')->first()?->completed_at,
            'blocks_count' => $this->blocks()->count(),
            'exercises_count' => $this->exercises()->count(),
            'unique_attendees' => RoutineCompletionAttendee::whereIn('routine_completion_id',
                $completions->pluck('id'))->distinct('student_id')->count(),
        ];
    }

    /**
     * Get work type distribution for this routine
     */
    public function getWorkTypeDistributionAttribute(): array
    {
        $exercises = $this->exercises()->get();
        $totalDuration = $exercises->sum('total_duration');

        if ($totalDuration === 0) {
            return [];
        }

        $distribution = [];
        foreach ($exercises as $exercise) {
            $workType = $exercise->work_type;
            $distribution[$workType] = ($distribution[$workType] ?? 0) +
                (($exercise->total_duration / $totalDuration) * 100);
        }

        return $distribution;
    }

    /**
     * Get upcoming scheduled instances
     */
    public function getUpcomingSchedulesAttribute()
    {
        if ($this->repeat_in_days <= 0 || !$this->scheduled_days) {
            return collect();
        }

        $lastCompletion = $this->completions()->latest('completed_at')->first();
        $startDate = $lastCompletion ? $lastCompletion->completed_at->addDays($this->repeat_in_days) : now();

        return collect($this->scheduled_days)->map(function ($dayOfWeek) use ($startDate) {
            return $startDate->copy()->next($dayOfWeek);
        })->sortBy(function ($date) {
            return $date->timestamp;
        });
    }

    /**
     * Check if routine is ready to be scheduled again
     */
    public function isReadyForScheduling(): bool
    {
        if ($this->repeat_in_days <= 0) {
            return false;
        }

        $lastCompletion = $this->completions()->latest('completed_at')->first();

        if (!$lastCompletion) {
            return true;
        }

        return $lastCompletion->completed_at->addDays($this->repeat_in_days)->isPast();
    }

    /**
     * Get popular routines dashboard
     */
    public static function getPopularRoutines(int $limit = 10)
    {
        return static::active()
            ->orderBy('usage_count', 'desc')
            ->orderBy('average_rating', 'desc')
            ->limit($limit)
            ->with(['categories', 'creator', 'blocks'])
            ->get();
    }

    /**
     * Get recently created routines
     */
    public static function getRecentRoutines(int $days = 7, int $limit = 10)
    {
        return static::active()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->with(['categories', 'creator', 'blocks'])
            ->get();
    }
}
