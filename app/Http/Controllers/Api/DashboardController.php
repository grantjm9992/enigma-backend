<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Routine;
use App\Models\Exercise;
use App\Models\Category;
use App\Models\Tag;
use App\Models\PlannedClass;
use App\Models\RoutineCompletion;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    /**
     * Get dashboard overview data
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        // Base query modifiers based on user role
        $isAdmin = $user->isAdmin();
        $isTrainer = $user->isTrainer();

        // Get date ranges
        $today = Carbon::today();
        $thisWeek = Carbon::now()->startOfWeek();
        $thisMonth = Carbon::now()->startOfMonth();
        $lastMonth = Carbon::now()->subMonth()->startOfMonth();

        // Users stats (admin/trainer only)
        $userStats = [];
        if ($isAdmin || $isTrainer) {
            $userQuery = User::query();
            if (!$isAdmin) {
                $userQuery->where('role', 'student');
            }

            $userStats = [
                'total_users' => $userQuery->count(),
                'active_users' => $userQuery->where('is_active', true)->count(),
                'new_this_month' => $userQuery->where('created_at', '>=', $thisMonth)->count(),
                'by_role' => $userQuery->select('role', DB::raw('count(*) as count'))
                    ->groupBy('role')
                    ->pluck('count', 'role')
                    ->toArray(),
            ];
        }

        // Routines stats
        $routineQuery = Routine::query();
        if (!$isAdmin && !$isTrainer) {
            $routineQuery->where(function ($q) use ($user) {
                $q->where('visibility', 'public')
                    ->orWhere('created_by', $user->id);
            });
        }

        $routineStats = [
            'total_routines' => $routineQuery->count(),
            'active_routines' => $routineQuery->where('is_active', true)->count(),
            'template_routines' => $routineQuery->where('is_template', true)->count(),
            'favorite_routines' => $routineQuery->where('is_favorite', true)->count(),
            'created_this_month' => $routineQuery->where('created_at', '>=', $thisMonth)->count(),
        ];

        // Exercises stats
        $exerciseQuery = Exercise::query();
        if (!$isAdmin && !$isTrainer) {
            $exerciseQuery->where(function ($q) use ($user) {
                $q->where('visibility', 'public')
                    ->orWhere('created_by', $user->id);
            });
        }

        $exerciseStats = [
            'total_exercises' => $exerciseQuery->count(),
            'active_exercises' => $exerciseQuery->where('is_active', true)->count(),
            'template_exercises' => $exerciseQuery->where('is_template', true)->count(),
            'multi_timer_exercises' => $exerciseQuery->where('is_multi_timer', true)->count(),
            'created_this_month' => $exerciseQuery->where('created_at', '>=', $thisMonth)->count(),
        ];

        // Planned classes stats
        $classQuery = PlannedClass::query();
        if (!$isAdmin && !$isTrainer) {
            $classQuery->where('created_by', $user->id);
        }

        $classStats = [
            'total_classes' => $classQuery->count(),
            'today_classes' => $classQuery->whereDate('date', $today)->count(),
            'this_week_classes' => $classQuery->whereBetween('date', [$thisWeek, $thisWeek->copy()->endOfWeek()])->count(),
            'upcoming_classes' => $classQuery->where('date', '>', $today)->count(),
            'completed_classes' => $classQuery->where('status', 'completed')->count(),
        ];

        // Routine completions stats
        $completionQuery = RoutineCompletion::query();
        if (!$isAdmin && !$isTrainer) {
            $completionQuery->where('completed_by', $user->id);
        }

        $completionStats = [
            'total_completions' => $completionQuery->count(),
            'today_completions' => $completionQuery->whereDate('completed_at', $today)->count(),
            'this_week_completions' => $completionQuery->whereBetween('completed_at', [$thisWeek, $thisWeek->copy()->endOfWeek()])->count(),
            'this_month_completions' => $completionQuery->where('completed_at', '>=', $thisMonth)->count(),
            'average_rating' => $completionQuery->whereNotNull('rating')->avg('rating'),
        ];

        // Categories and tags stats
        $categoryStats = [
            'total_categories' => Category::where('is_active', true)->count(),
            'by_type' => Category::where('is_active', true)
                ->select('type', DB::raw('count(*) as count'))
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
        ];

        $tagStats = [
            'total_tags' => Tag::where('is_active', true)->count(),
        ];

        return response()->json([
            'user_stats' => $userStats,
            'routine_stats' => $routineStats,
            'exercise_stats' => $exerciseStats,
            'class_stats' => $classStats,
            'completion_stats' => $completionStats,
            'category_stats' => $categoryStats,
            'tag_stats' => $tagStats,
            'generated_at' => now(),
        ]);
    }

    /**
     * Get detailed analytics data
     */
    public function analytics(Request $request): JsonResponse
    {
        $user = $request->user();
        $dateRange = $request->get('date_range', '30'); // days
        $startDate = Carbon::now()->subDays($dateRange);

        // Routine completions over time
        $completionsOverTime = RoutineCompletion::select(
            DB::raw('DATE(completed_at) as date'),
            DB::raw('COUNT(*) as count'),
            DB::raw('AVG(duration) as avg_duration'),
            DB::raw('AVG(rating) as avg_rating')
        )
            ->where('completed_at', '>=', $startDate)
            ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                $q->where('completed_by', $user->id);
            })
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Most used categories
        $categoryUsage = Category::withCount(['routines', 'exercises'])
            ->orderByDesc('routines_count')
            ->orderByDesc('exercises_count')
            ->limit(10)
            ->get()
            ->map(function ($category) {
                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'color' => $category->color,
                    'type' => $category->type,
                    'routines_count' => $category->routines_count,
                    'exercises_count' => $category->exercises_count,
                    'total_usage' => $category->routines_count + $category->exercises_count,
                ];
            });

        // Work type distribution (from exercises)
        $workTypeDistribution = Exercise::select('work_type', DB::raw('COUNT(*) as count'))
            ->where('is_active', true)
            ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                $q->where(function ($subQ) use ($user) {
                    $subQ->where('visibility', 'public')
                        ->orWhere('created_by', $user->id);
                });
            })
            ->groupBy('work_type')
            ->get()
            ->pluck('count', 'work_type')
            ->toArray();

        // Difficulty distribution
        $difficultyDistribution = [
            'routines' => Routine::select('difficulty', DB::raw('COUNT(*) as count'))
                ->where('is_active', true)
                ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                    $q->where(function ($subQ) use ($user) {
                        $subQ->where('visibility', 'public')
                            ->orWhere('created_by', $user->id);
                    });
                })
                ->groupBy('difficulty')
                ->pluck('count', 'difficulty')
                ->toArray(),
            'exercises' => Exercise::select('difficulty', DB::raw('COUNT(*) as count'))
                ->where('is_active', true)
                ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                    $q->where(function ($subQ) use ($user) {
                        $subQ->where('visibility', 'public')
                            ->orWhere('created_by', $user->id);
                    });
                })
                ->groupBy('difficulty')
                ->pluck('count', 'difficulty')
                ->toArray(),
        ];

        // Student participation (trainers/admins only)
        $studentParticipation = [];
        if ($user->isAdmin() || $user->isTrainer()) {
            $studentParticipation = User::where('role', 'student')
                ->withCount(['completedRoutines' => function ($q) use ($startDate) {
                    $q->where('completed_at', '>=', $startDate);
                }])
                ->orderByDesc('completed_routines_count')
                ->limit(10)
                ->get()
                ->map(function ($student) {
                    return [
                        'id' => $student->id,
                        'name' => $student->first_name . ' ' . $student->last_name,
                        'email' => $student->email,
                        'completions_count' => $student->completed_routines_count,
                    ];
                });
        }

        return response()->json([
            'completions_over_time' => $completionsOverTime,
            'category_usage' => $categoryUsage,
            'work_type_distribution' => $workTypeDistribution,
            'difficulty_distribution' => $difficultyDistribution,
            'student_participation' => $studentParticipation,
            'date_range' => $dateRange,
            'start_date' => $startDate->toDateString(),
            'end_date' => now()->toDateString(),
        ]);
    }

    /**
     * Get recent activity
     */
    public function recentActivity(Request $request): JsonResponse
    {
        $user = $request->user();
        $limit = $request->get('limit', 20);

        $activities = collect();

        // Recent routine completions
        $recentCompletions = RoutineCompletion::with(['routine', 'completedByUser'])
            ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                $q->where('completed_by', $user->id);
            })
            ->orderBy('completed_at', 'desc')
            ->limit($limit / 2)
            ->get()
            ->map(function ($completion) {
                return [
                    'type' => 'routine_completion',
                    'id' => $completion->id,
                    'title' => 'Rutina completada: ' . $completion->routine->name,
                    'description' => 'Duración: ' . $completion->duration . ' min, Rating: ' . ($completion->rating ?? 'N/A'),
                    'user' => $completion->completedByUser->first_name . ' ' . $completion->completedByUser->last_name,
                    'timestamp' => $completion->completed_at,
                    'icon' => 'check-circle',
                    'color' => 'green',
                ];
            });

        // Recent planned classes
        $recentClasses = PlannedClass::with(['routine', 'creator'])
            ->when(!$user->isAdmin() && !$user->isTrainer(), function ($q) use ($user) {
                $q->where('created_by', $user->id);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit / 2)
            ->get()
            ->map(function ($class) {
                return [
                    'type' => 'planned_class',
                    'id' => $class->id,
                    'title' => 'Clase programada: ' . $class->title,
                    'description' => 'Fecha: ' . $class->date . ' - ' . $class->class_type,
                    'user' => $class->creator->first_name . ' ' . $class->creator->last_name,
                    'timestamp' => $class->created_at,
                    'icon' => 'calendar',
                    'color' => 'blue',
                ];
            });

        // Combine and sort activities
        $activities = $recentCompletions->concat($recentClasses)
            ->sortByDesc('timestamp')
            ->take($limit)
            ->values();

        return response()->json([
            'activities' => $activities,
            'total' => $activities->count(),
        ]);
    }
}
