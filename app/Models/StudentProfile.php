<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'age',
        'height',
        'weight',
        'last_weight_update',
        'level',
        'strengths',
        'weaknesses',
        'notes',
        'tactical_notes',
        'last_tactical_notes_update',
        'pending_notes',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'strengths' => 'array',
            'weaknesses' => 'array',
            'pending_notes' => 'array',
            'last_weight_update' => 'datetime',
            'last_tactical_notes_update' => 'datetime',
            'height' => 'decimal:2',
            'weight' => 'decimal:2',
        ];
    }

    /**
     * Get the user that owns the student profile
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Update weight and track the date
     */
    public function updateWeight(float $weight): void
    {
        $this->update([
            'weight' => $weight,
            'last_weight_update' => now(),
        ]);
    }

    /**
     * Update tactical notes and track the date
     */
    public function updateTacticalNotes(string $notes): void
    {
        $this->update([
            'tactical_notes' => $notes,
            'last_tactical_notes_update' => now(),
        ]);
    }

    /**
     * Add a pending note
     */
    public function addPendingNote(string $note): void
    {
        $pendingNotes = $this->pending_notes ?? [];
        $pendingNotes[] = [
            'note' => $note,
            'created_at' => now()->toISOString(),
        ];

        $this->update(['pending_notes' => $pendingNotes]);
    }

    /**
     * Remove a pending note by index
     */
    public function removePendingNote(int $index): void
    {
        $pendingNotes = $this->pending_notes ?? [];

        if (isset($pendingNotes[$index])) {
            unset($pendingNotes[$index]);
            $this->update(['pending_notes' => array_values($pendingNotes)]);
        }
    }

    /**
     * Get BMI if height and weight are available
     */
    public function getBmiAttribute(): ?float
    {
        if ($this->height && $this->weight) {
            $heightInMeters = $this->height / 100;
            return round($this->weight / ($heightInMeters * $heightInMeters), 2);
        }

        return null;
    }

    /**
     * Get level label in Spanish
     */
    public function getLevelLabelAttribute(): string
    {
        $labels = [
            'principiante' => 'Principiante',
            'intermedio' => 'Intermedio',
            'avanzado' => 'Avanzado',
            'competidor' => 'Competidor',
            'elite' => 'Élite',
        ];

        return $labels[$this->level] ?? $this->level;
    }

    /**
     * Scope for filtering by level
     */
    public function scopeByLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    /**
     * Scope for filtering by age range
     */
    public function scopeByAgeRange($query, int $minAge, int $maxAge)
    {
        return $query->whereBetween('age', [$minAge, $maxAge]);
    }
}
