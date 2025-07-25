<?php

namespace App\Models;

use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasOne;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'email',
        'password',
        'role',
        'first_name',
        'last_name',
        'phone',
        'profile_picture',
        'subscription_plan',
        'temp_password',
        'temp_password_expiry',
        'is_active',
        'is_email_verified',
        'last_login',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'temp_password',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'temp_password_expiry' => 'datetime',
            'last_login' => 'datetime',
            'is_active' => 'boolean',
            'is_email_verified' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * Check if user is a student
     */
    public function isStudent(): bool
    {
        return $this->role === 'student';
    }

    /**
     * Check if user is a trainer
     */
    public function isTrainer(): bool
    {
        return $this->role === 'trainer';
    }

    /**
     * Check if user is an admin
     */
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    /**
     * Get the student profile associated with the user (if student)
     */
    public function studentProfile(): HasOne
    {
        return $this->hasOne(StudentProfile::class);
    }

    /**
     * Check if user has a temporary password
     */
    public function hasTempPassword(): bool
    {
        return !is_null($this->temp_password) &&
            !is_null($this->temp_password_expiry) &&
            $this->temp_password_expiry > now();
    }

    /**
     * Generate a temporary password
     */
    public function generateTempPassword(): string
    {
        $tempPassword = str()->random(8);
        $this->update([
            'temp_password' => $tempPassword,
            'temp_password_expiry' => now()->addDays(30),
        ]);

        return $tempPassword;
    }

    /**
     * Clear temporary password
     */
    public function clearTempPassword(): void
    {
        $this->update([
            'temp_password' => null,
            'temp_password_expiry' => null,
        ]);
    }

    /**
     * Get full name
     */
    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    /**
     * Scope for active users
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for students
     */
    public function scopeStudents($query)
    {
        return $query->where('role', 'student');
    }

    /**
     * Scope for trainers
     */
    public function scopeTrainers($query)
    {
        return $query->where('role', 'trainer');
    }

    /**
     * Scope for admins
     */
    public function scopeAdmins($query)
    {
        return $query->where('role', 'admin');
    }
}
