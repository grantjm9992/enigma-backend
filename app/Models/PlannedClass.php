<?php

namespace App\Models;


use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlannedClass extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'date',
        'start_time',
        'end_time',
        'duration',
        'routine_id',
        'class_type',
        'max_participants',
        'target_students',
        'materials_needed',
        'notes',
        'status',
        'routine_completion_id',
        'created_by',
        'notion_page_id',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'start_time' => 'datetime:H:i',
            'end_time' => 'datetime:H:i',
            'duration' => 'integer',
            'max_participants' => 'integer',
            'target_students' => 'array',
            'materials_needed' => 'array',
            'notes' => 'array',
        ];
    }

    public function routine(): BelongsTo
    {
        return $this->belongsTo(Routine::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completion(): BelongsTo
    {
        return $this->belongsTo(RoutineCompletion::class, 'routine_completion_id');
    }

    public function targetStudentsUsers()
    {
        if (!$this->target_students) {
            return collect();
        }

        return User::whereIn('id', $this->target_students)->get();
    }

    public function getFormattedTimeAttribute(): string
    {
        return $this->start_time->format('H:i') . ' - ' . $this->end_time->format('H:i');
    }

    public function getStatusLabelAttribute(): string
    {
        $labels = [
            'planned' => 'Planificada',
            'in_progress' => 'En Progreso',
            'completed' => 'Completada',
            'cancelled' => 'Cancelada',
        ];

        return $labels[$this->status] ?? $this->status;
    }

    public function getClassTypeLabelAttribute(): string
    {
        $labels = [
            'morning' => 'Mañana',
            'afternoon' => 'Tarde',
            'evening' => 'Noche',
            'custom' => 'Personalizada',
        ];

        return $labels[$this->class_type] ?? $this->class_type;
    }

    public function isUpcoming(): bool
    {
        return $this->date->isFuture() ||
            ($this->date->isToday() && $this->start_time->isFuture());
    }

    public function isPast(): bool
    {
        return $this->date->isPast() ||
            ($this->date->isToday() && $this->end_time->isPast());
    }

    public function canBeStarted(): bool
    {
        return $this->status === 'planned' &&
            $this->date->isToday() &&
            now()->between($this->start_time->subMinutes(15), $this->end_time);
    }

    public function scopeUpcoming($query)
    {
        return $query->where('status', 'planned')
            ->where(function ($q) {
                $q->where('date', '>', now()->toDateString())
                    ->orWhere(function ($subQ) {
                        $subQ->where('date', now()->toDateString())
                            ->where('start_time', '>', now()->toTimeString());
                    });
            });
    }

    public function scopeToday($query)
    {
        return $query->where('date', now()->toDateString());
    }

    public function scopeByStatus($query, string $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByClassType($query, string $classType)
    {
        return $query->where('class_type', $classType);
    }
}
