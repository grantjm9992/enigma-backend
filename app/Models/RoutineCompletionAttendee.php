<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoutineCompletionAttendee extends Model
{
    use HasFactory;

    protected $fillable = [
        'routine_completion_id',
        'student_id',
        'participation_minutes',
        'performance_notes',
        'completed_full_session',
    ];

    protected function casts(): array
    {
        return [
            'participation_minutes' => 'integer',
            'performance_notes' => 'array',
            'completed_full_session' => 'boolean',
        ];
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(RoutineCompletion::class, 'routine_completion_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function getParticipationPercentageAttribute(): float
    {
        $totalDuration = $this->completion->actual_duration;
        if ($totalDuration === 0) return 100;

        return ($this->participation_minutes / $totalDuration) * 100;
    }

    public function scopeFullParticipation($query)
    {
        return $query->where('completed_full_session', true);
    }

    public function scopePartialParticipation($query)
    {
        return $query->where('completed_full_session', false);
    }
}
