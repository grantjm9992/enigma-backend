<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutineCompletion extends Model
{
    use HasFactory;

    protected $fillable = [
        'routine_id',
        'routine_name',
        'category_id',
        'category_name',
        'completed_at',
        'planned_duration',
        'actual_duration',
        'notes',
        'rating',
        'morning_session',
        'afternoon_session',
        'is_full_day_complete',
        'block_completions',
        'exercise_completions',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'completed_at' => 'datetime',
            'planned_duration' => 'integer',
            'actual_duration' => 'integer',
            'rating' => 'integer',
            'morning_session' => 'boolean',
            'afternoon_session' => 'boolean',
            'is_full_day_complete' => 'boolean',
            'block_completions' => 'array',
            'exercise_completions' => 'array',
            'notes' => 'array',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(RoutineCompletionAttendee::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'routine_completion_attendees', 'routine_completion_id', 'student_id')
            ->withPivot(['participation_minutes', 'performance_notes', 'completed_full_session'])
            ->withTimestamps();
    }

    public function plannedClass(): BelongsTo
    {
        return $this->belongsTo(PlannedClass::class);
    }

    public function getDurationDifferenceAttribute(): int
    {
        return $this->actual_duration - $this->planned_duration;
    }

    public function getEfficiencyPercentageAttribute(): float
    {
        if ($this->planned_duration === 0) return 100;
        return ($this->actual_duration / $this->planned_duration) * 100;
    }

    public function scopeByDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('completed_at', [$startDate, $endDate]);
    }

    public function scopeMorningSessions($query)
    {
        return $query->where('morning_session', true);
    }

    public function scopeAfternoonSessions($query)
    {
        return $query->where('afternoon_session', true);
    }

    public function scopeRated($query)
    {
        return $query->whereNotNull('rating');
    }
}
