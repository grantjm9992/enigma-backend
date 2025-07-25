<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Exercise extends Model
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
        'duration',
        'intensity',
        'work_type',
        'difficulty',
        'tags',
        'materials',
        'protection',
        'instructions',
        'video_url',
        'image_url',
        'is_multi_timer',
        'timers',
        'is_template',
        'is_active',
        'visibility',
        'created_by',
        'usage_count',
        'average_rating',
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
            'instructions' => 'array',
            'timers' => 'array',
            'is_multi_timer' => 'boolean',
            'is_template' => 'boolean',
            'is_active' => 'boolean',
            'usage_count' => 'integer',
            'average_rating' => 'decimal:2',
        ];
    }

    /**
     * Get the user who created this exercise
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Get the categories that belong to this exercise
     */
    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'exercise_categories')
            ->withTimestamps();
    }

    /**
     * Get the routine blocks that use this exercise
     */
    public function routineBlocks(): BelongsToMany
    {
        return $this->belongsToMany(RoutineBlock::class, 'routine_block_exercises')
            ->withPivot(['sort_order', 'duration_override', 'exercise_notes', 'custom_timers'])
            ->withTimestamps();
    }

    /**
     * Scope for active exercises
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for exercises by work type
     */
    public function scopeByWorkType($query, string $workType)
    {
        return $query->where('work_type', $workType);
    }

    /**
     * Scope for exercises by difficulty
     */
    public function scopeByDifficulty($query, string $difficulty)
    {
        return $query->where('difficulty', $difficulty);
    }

    /**
     * Scope for exercises by intensity
     */
    public function scopeByIntensity($query, string $intensity)
    {
        return $query->where('intensity', $intensity);
    }

    /**
     * Scope for template exercises
     */
    public function scopeTemplates($query)
    {
        return $query->where('is_template', true);
    }

    /**
     * Scope for exercises visible to a user
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', 'public')
                ->orWhere('created_by', $user->id)
                ->orWhere(function ($subQ) use ($user) {
                    $subQ->where('visibility', 'shared')
                        ->whereIn('created_by', function ($userQ) use ($user) {
                            // Trainers can see other trainers' shared exercises
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
     * Scope for multi-timer exercises
     */
    public function scopeMultiTimer($query)
    {
        return $query->where('is_multi_timer', true);
    }

    /**
     * Scope for regular (non-multi-timer) exercises
     */
    public function scopeRegular($query)
    {
        return $query->where('is_multi_timer', false);
    }

    /**
     * Search exercises by name, description, or tags
     */
    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")
                ->orWhereJsonContains('tags', $search);
        });
    }

    /**
     * Get formatted duration for display
     */
    public function getFormattedDurationAttribute(): string
    {
        if ($this->is_multi_timer && $this->timers) {
            $totalDuration = collect($this->timers)->sum(function ($timer) {
                $timerDuration = ($timer['duration'] ?? 0) * ($timer['repetitions'] ?? 1);
                $restDuration = ($timer['restBetween'] ?? 0) * (($timer['repetitions'] ?? 1) - 1);
                return $timerDuration + $restDuration;
            });

            return $totalDuration >= 60
                ? sprintf('%dh %dm', intval($totalDuration / 60), $totalDuration % 60)
                : "{$totalDuration}m";
        }

        return $this->duration >= 60
            ? sprintf('%dh %dm', intval($this->duration / 60), $this->duration % 60)
            : "{$this->duration}m";
    }

    /**
     * Get calculated total duration (including multi-timer)
     */
    public function getTotalDurationAttribute(): int
    {
        if ($this->is_multi_timer && $this->timers) {
            return collect($this->timers)->sum(function ($timer) {
                $timerDuration = ($timer['duration'] ?? 0) * ($timer['repetitions'] ?? 1);
                $restDuration = ($timer['restBetween'] ?? 0) * (($timer['repetitions'] ?? 1) - 1);
                return $timerDuration + $restDuration;
            });
        }

        return $this->duration;
    }

    /**
     * Get work type label in Spanish
     */
    public function getWorkTypeLabelAttribute(): string
    {
        $labels = [
            'strength' => 'Fuerza',
            'coordination' => 'Coordinación',
            'reaction' => 'Reacción',
            'technique' => 'Técnica',
            'cardio' => 'Cardio',
            'flexibility' => 'Flexibilidad',
            'sparring' => 'Sparring',
            'conditioning' => 'Acondicionamiento',
        ];

        return $labels[$this->work_type] ?? $this->work_type;
    }

    /**
     * Get intensity label in Spanish
     */
    public function getIntensityLabelAttribute(): string
    {
        $labels = [
            'low' => 'Baja',
            'medium' => 'Media',
            'high' => 'Alta',
        ];

        return $labels[$this->intensity] ?? $this->intensity;
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
     * Increment usage count
     */
    public function incrementUsage(): void
    {
        $this->increment('usage_count');
    }

    /**
     * Update average rating
     */
    public function updateAverageRating(array $ratings): void
    {
        $this->update([
            'average_rating' => collect($ratings)->avg()
        ]);
    }

    /**
     * Clone exercise with modifications
     */
    public function cloneExercise(array $modifications = []): Exercise
    {
        $attributes = $this->toArray();
        unset($attributes['id'], $attributes['created_at'], $attributes['updated_at']);

        $attributes['name'] = ($modifications['name'] ?? $attributes['name']) . ' (Copia)';
        $attributes['is_template'] = false;
        $attributes['usage_count'] = 0;
        $attributes['average_rating'] = null;
        $attributes['created_by'] = auth()->id();

        $clone = static::create(array_merge($attributes, $modifications));

        // Clone category relationships
        $clone->categories()->attach($this->categories->pluck('id'));

        return $clone;
    }

    /**
     * Get exercise statistics
     */
    public function getStatsAttribute(): array
    {
        return [
            'usage_count' => $this->usage_count,
            'average_rating' => $this->average_rating,
            'total_duration_used' => $this->routineBlocks()
                    ->join('routine_completions', 'routine_blocks.routine_id', '=', 'routine_completions.routine_id')
                    ->count() * $this->total_duration,
            'categories_count' => $this->categories()->count(),
            'in_routines_count' => $this->routineBlocks()->distinct('routine_blocks.routine_id')->count(),
        ];
    }

    /**
     * Get popular exercises dashboard
     */
    public static function getPopularExercises(int $limit = 10)
    {
        return static::active()
            ->orderBy('usage_count', 'desc')
            ->orderBy('average_rating', 'desc')
            ->limit($limit)
            ->with(['categories', 'creator'])
            ->get();
    }

    /**
     * Get recently created exercises
     */
    public static function getRecentExercises(int $days = 7, int $limit = 10)
    {
        return static::active()
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->with(['categories', 'creator'])
            ->get();
    }
}
