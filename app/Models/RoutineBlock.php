<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RoutineBlock extends Model
{
    use HasFactory;

    protected $fillable = [
        'routine_id',
        'name',
        'description',
        'color',
        'notes',
        'sort_order',
        'duration',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'duration' => 'integer',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function exercises(): BelongsToMany
    {
        return $this->belongsToMany(Exercise::class, 'routine_block_exercises')
            ->withPivot(['sort_order', 'duration_override', 'exercise_notes', 'custom_timers'])
            ->orderBy('routine_block_exercises.sort_order')
            ->withTimestamps();
    }

    public function calculateDuration(): int
    {
        return $this->exercises->sum(function ($exercise) {
            return $exercise->pivot->duration_override ?? $exercise->total_duration;
        });
    }

    public function updateDuration(): void
    {
        $this->update(['duration' => $this->calculateDuration()]);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
