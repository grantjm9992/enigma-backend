<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;
use App\Models\Category;
use App\Models\Exercise;
use App\Models\Routine;
use App\Models\RoutineBlock;
use App\Models\PlannedClass;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default admin user
        $admin = User::create([
            'email' => 'admin@enigmaboxing.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'first_name' => 'Admin',
            'last_name' => 'Enigma',
            'subscription_plan' => 'premium',
            'is_active' => true,
            'is_email_verified' => true,
            'last_login' => now(),
        ]);

        // Create default trainer
        $trainer = User::create([
            'email' => 'trainer@enigmaboxing.com',
            'password' => Hash::make('trainer123'),
            'role' => 'trainer',
            'first_name' => 'Carlos',
            'last_name' => 'Entrenador',
            'phone' => '+34 666 777 888',
            'subscription_plan' => 'premium',
            'is_active' => true,
            'is_email_verified' => true,
        ]);

        // Create sample students with profiles
        $students = [
            [
                'email' => 'juan.perez@email.com',
                'first_name' => 'Juan',
                'last_name' => 'Pérez',
                'phone' => '+34 600 111 222',
                'profile' => [
                    'age' => 25,
                    'height' => 175.0,
                    'weight' => 70.0,
                    'level' => 'intermedio',
                    'strengths' => ['Velocidad', 'Cardio'],
                    'weaknesses' => ['Defensa', 'Potencia'],
                    'notes' => 'Estudiante muy dedicado, necesita trabajar más la técnica defensiva.',
                ]
            ],
            [
                'email' => 'maria.garcia@email.com',
                'first_name' => 'María',
                'last_name' => 'García',
                'phone' => '+34 600 333 444',
                'profile' => [
                    'age' => 22,
                    'height' => 160.0,
                    'weight' => 55.0,
                    'level' => 'principiante',
                    'strengths' => ['Técnica', 'Disciplina'],
                    'weaknesses' => ['Confianza', 'Agresividad'],
                    'notes' => 'Muy técnica pero le falta confianza en el ring.',
                ]
            ],
            [
                'email' => 'carlos.lopez@email.com',
                'first_name' => 'Carlos',
                'last_name' => 'López',
                'phone' => '+34 600 555 666',
                'profile' => [
                    'age' => 28,
                    'height' => 180.0,
                    'weight' => 80.0,
                    'level' => 'avanzado',
                    'strengths' => ['Potencia', 'Experiencia', 'Mentalidad'],
                    'weaknesses' => ['Velocidad'],
                    'notes' => 'Boxeador experimentado, buen sparring partner.',
                    'tactical_notes' => 'Utilizar jab doble para mantener distancia. Trabajar combinaciones al cuerpo.',
                ]
            ],
            [
                'email' => 'ana.martinez@email.com',
                'first_name' => 'Ana',
                'last_name' => 'Martínez',
                'phone' => '+34 600 777 888',
                'profile' => [
                    'age' => 19,
                    'height' => 165.0,
                    'weight' => 58.0,
                    'level' => 'competidor',
                    'strengths' => ['Velocidad', 'Técnica', 'Cardio', 'Estrategia'],
                    'weaknesses' => ['Experiencia en competición'],
                    'notes' => 'Talento natural, lista para competir.',
                    'tactical_notes' => 'Enfocarse en boxing inteligente. Aprovechar su velocidad superior.',
                ]
            ],
            [
                'email' => 'pedro.rodriguez@email.com',
                'first_name' => 'Pedro',
                'last_name' => 'Rodríguez',
                'phone' => '+34 600 999 000',
                'profile' => [
                    'age' => 35,
                    'height' => 170.0,
                    'weight' => 75.0,
                    'level' => 'elite',
                    'strengths' => ['Experiencia', 'Mentalidad', 'Técnica', 'Estrategia'],
                    'weaknesses' => ['Recuperación'],
                    'notes' => 'Veterano del club, excelente mentor para nuevos estudiantes.',
                    'tactical_notes' => 'Boxing cerebral. Utilizar la experiencia para anticipar movimientos.',
                ]
            ]
        ];

        $studentUsers = [];
        foreach ($students as $studentData) {
            $student = User::create([
                'email' => $studentData['email'],
                'password' => Hash::make('student123'),
                'role' => 'student',
                'first_name' => $studentData['first_name'],
                'last_name' => $studentData['last_name'],
                'phone' => $studentData['phone'],
                'subscription_plan' => 'basic',
                'is_active' => true,
                'is_email_verified' => true,
            ]);

            // Create student profile
            $profile = $studentData['profile'];
            $student->studentProfile()->create([
                'age' => $profile['age'],
                'height' => $profile['height'],
                'weight' => $profile['weight'],
                'last_weight_update' => now()->subDays(rand(1, 30)),
                'level' => $profile['level'],
                'strengths' => $profile['strengths'],
                'weaknesses' => $profile['weaknesses'],
                'notes' => $profile['notes'],
                'tactical_notes' => $profile['tactical_notes'] ?? null,
                'last_tactical_notes_update' => isset($profile['tactical_notes']) ? now()->subDays(rand(1, 7)) : null,
                'pending_notes' => []
            ]);

            $studentUsers[] = $student;
        }

        // Create categories
        $categories = [
            ['name' => 'Calentamiento', 'description' => 'Ejercicios de preparación física', 'color' => '#10B981', 'type' => 'phase'],
            ['name' => 'Técnica Básica', 'description' => 'Fundamentos del boxeo', 'color' => '#3B82F6', 'type' => 'phase'],
            ['name' => 'Cardio', 'description' => 'Ejercicios cardiovasculares', 'color' => '#EF4444', 'type' => 'load-type'],
            ['name' => 'Fuerza', 'description' => 'Entrenamiento de fuerza', 'color' => '#F59E0B', 'type' => 'load-type'],
            ['name' => 'Sparring', 'description' => 'Combate controlado', 'color' => '#8B5CF6', 'type' => 'phase'],
            ['name' => 'Flexibilidad', 'description' => 'Estiramientos y movilidad', 'color' => '#06B6D4', 'type' => 'phase'],
            ['name' => 'Principiantes', 'description' => 'Ejercicios para nuevos boxeadores', 'color' => '#84CC16', 'type' => 'custom'],
            ['name' => 'Competición', 'description' => 'Preparación para competir', 'color' => '#EC4899', 'type' => 'period'],
        ];

        $categoryModels = [];
        foreach ($categories as $index => $categoryData) {
            $category = Category::create(array_merge($categoryData, ['sort_order' => $index + 1]));
            $categoryModels[] = $category;
        }

        // Create exercises
        $exercises = [
            // Calentamiento
            [
                'name' => 'Salto de Cuerda',
                'description' => 'Ejercicio básico de calentamiento con cuerda',
                'duration' => 10,
                'intensity' => 'medium',
                'work_type' => 'cardio',
                'difficulty' => 'beginner',
                'tags' => ['calentamiento', 'cardio', 'coordinación'],
                'materials' => ['Cuerda de saltar'],
                'instructions' => [
                    'Tomar la cuerda con ambas manos',
                    'Mantener codos cerca del cuerpo',
                    'Saltar con ambos pies alternando ritmo',
                    'Mantener respiración constante'
                ],
                'categories' => [0], // Calentamiento
            ],
            [
                'name' => 'Shadowboxing',
                'description' => 'Boxeo contra la sombra para técnica y calentamiento',
                'duration' => 15,
                'intensity' => 'medium',
                'work_type' => 'technique',
                'difficulty' => 'beginner',
                'tags' => ['técnica', 'calentamiento', 'shadowboxing'],
                'materials' => [],
                'instructions' => [
                    'Posición de guardia correcta',
                    'Combinar jabs, directos, ganchos',
                    'Mantener movimiento de pies',
                    'Visualizar oponente'
                ],
                'categories' => [0, 1], // Calentamiento, Técnica Básica
            ],
            [
                'name' => 'Trabajo de Pads',
                'description' => 'Entrenamiento con paos para técnica y precisión',
                'duration' => 20,
                'intensity' => 'high',
                'work_type' => 'technique',
                'difficulty' => 'intermediate',
                'tags' => ['técnica', 'precisión', 'potencia'],
                'materials' => ['Paos', 'Guantes'],
                'protection' => ['Guantes de boxeo', 'Vendas'],
                'instructions' => [
                    'Partner sujeta los paos',
                    'Combinar golpes según indicaciones',
                    'Mantener técnica correcta',
                    'Trabajar potencia y velocidad'
                ],
                'categories' => [1], // Técnica Básica
            ],
            [
                'name' => 'Saco Pesado',
                'description' => 'Entrenamiento con saco para potencia',
                'duration' => 25,
                'intensity' => 'high',
                'work_type' => 'strength',
                'difficulty' => 'intermediate',
                'tags' => ['potencia', 'técnica', 'resistencia'],
                'materials' => ['Saco pesado', 'Guantes'],
                'protection' => ['Guantes de boxeo', 'Vendas'],
                'instructions' => [
                    'Calentar antes de empezar',
                    'Combinar golpes de poder',
                    'Mantener distancia correcta',
                    'Trabajar diferentes niveles'
                ],
                'categories' => [1, 3], // Técnica Básica, Fuerza
            ],
            [
                'name' => 'Circuito de Cardio',
                'description' => 'Circuito de ejercicios cardiovasculares',
                'duration' => 30,
                'intensity' => 'high',
                'work_type' => 'cardio',
                'difficulty' => 'intermediate',
                'is_multi_timer' => true,
                'timers' => [
                    ['name' => 'Burpees', 'duration' => 1, 'repetitions' => 3, 'restBetween' => 0.5],
                    ['name' => 'Mountain Climbers', 'duration' => 1, 'repetitions' => 3, 'restBetween' => 0.5],
                    ['name' => 'Jump Squats', 'duration' => 1, 'repetitions' => 3, 'restBetween' => 0.5],
                ],
                'tags' => ['cardio', 'circuito', 'alta intensidad'],
                'materials' => [],
                'categories' => [2], // Cardio
            ],
            [
                'name' => 'Sparring Técnico',
                'description' => 'Combate controlado enfocado en técnica',
                'duration' => 20,
                'intensity' => 'medium',
                'work_type' => 'sparring',
                'difficulty' => 'advanced',
                'tags' => ['sparring', 'técnica', 'control'],
                'materials' => ['Ring o área delimitada'],
                'protection' => ['Casco', 'Guantes', 'Protector bucal', 'Coquilla'],
                'instructions' => [
                    'Equipamiento completo obligatorio',
                    'Supervisión de entrenador',
                    'Enfoque en técnica, no potencia',
                    'Parar ante cualquier problema'
                ],
                'categories' => [4], // Sparring
            ],
            [
                'name' => 'Estiramientos Post-Entreno',
                'description' => 'Rutina de estiramientos para recovery',
                'duration' => 15,
                'intensity' => 'low',
                'work_type' => 'flexibility',
                'difficulty' => 'beginner',
                'tags' => ['flexibilidad', 'recovery', 'relajación'],
                'materials' => ['Esterilla'],
                'instructions' => [
                    'Estirar todos los grupos musculares',
                    'Mantener cada estiramiento 30 segundos',
                    'Respiración profunda y relajada',
                    'No forzar los estiramientos'
                ],
                'categories' => [5], // Flexibilidad
            ]
        ];

        $exerciseModels = [];
        foreach ($exercises as $exerciseData) {
            $categories = $exerciseData['categories'];
            unset($exerciseData['categories']);

            $exercise = Exercise::create(array_merge($exerciseData, [
                'created_by' => $trainer->id,
                'visibility' => 'shared',
                'is_template' => true,
            ]));

            // Attach categories
            $exercise->categories()->attach(array_map(function($index) use ($categoryModels) {
                return $categoryModels[$index]->id;
            }, $categories));

            $exerciseModels[] = $exercise;
        }

        // Create sample routines
        $routines = [
            [
                'name' => 'Entrenamiento Principiantes',
                'description' => 'Rutina completa para boxeadores novatos',
                'objective' => 'Introducir fundamentos básicos del boxeo',
                'difficulty' => 'beginner',
                'level' => 'principiante',
                'tags' => ['principiante', 'fundamentos', 'básico'],
                'visibility' => 'shared',
                'is_template' => true,
                'blocks' => [
                    [
                        'name' => 'Calentamiento',
                        'description' => 'Preparación física inicial',
                        'color' => '#10B981',
                        'exercises' => [0, 1], // Salto de Cuerda, Shadowboxing
                    ],
                    [
                        'name' => 'Técnica Básica',
                        'description' => 'Trabajo fundamental de técnica',
                        'color' => '#3B82F6',
                        'exercises' => [2], // Trabajo de Pads
                    ],
                    [
                        'name' => 'Recuperación',
                        'description' => 'Vuelta a la calma',
                        'color' => '#06B6D4',
                        'exercises' => [6], // Estiramientos
                    ]
                ],
                'categories' => [0, 1, 6], // Calentamiento, Técnica Básica, Principiantes
            ],
            [
                'name' => 'Entrenamiento Cardio Intensivo',
                'description' => 'Sesión de alta intensidad cardiovascular',
                'objective' => 'Mejorar resistencia cardiovascular y explosividad',
                'difficulty' => 'intermediate',
                'level' => 'intermedio',
                'tags' => ['cardio', 'resistencia', 'intensivo'],
                'visibility' => 'shared',
                'is_template' => true,
                'blocks' => [
                    [
                        'name' => 'Calentamiento Dinámico',
                        'description' => 'Activación corporal',
                        'color' => '#10B981',
                        'exercises' => [0], // Salto de Cuerda
                    ],
                    [
                        'name' => 'Cardio Intensivo',
                        'description' => 'Circuito de alta intensidad',
                        'color' => '#EF4444',
                        'exercises' => [4, 1], // Circuito de Cardio, Shadowboxing
                    ],
                    [
                        'name' => 'Recovery',
                        'description' => 'Vuelta a la calma',
                        'color' => '#06B6D4',
                        'exercises' => [6], // Estiramientos
                    ]
                ],
                'categories' => [0, 2], // Calentamiento, Cardio
            ],
            [
                'name' => 'Sesión de Sparring',
                'description' => 'Entrenamiento de combate controlado',
                'objective' => 'Aplicar técnicas en situación de combate',
                'difficulty' => 'advanced',
                'level' => 'avanzado',
                'tags' => ['sparring', 'combate', 'aplicación'],
                'visibility' => 'shared',
                'is_template' => true,
                'blocks' => [
                    [
                        'name' => 'Preparación',
                        'description' => 'Calentamiento específico para sparring',
                        'color' => '#10B981',
                        'exercises' => [0, 1, 2], // Salto de Cuerda, Shadowboxing, Pads
                    ],
                    [
                        'name' => 'Sparring',
                        'description' => 'Combate técnico supervisado',
                        'color' => '#8B5CF6',
                        'exercises' => [5], // Sparring Técnico
                    ],
                    [
                        'name' => 'Recuperación',
                        'description' => 'Relajación post-combate',
                        'color' => '#06B6D4',
                        'exercises' => [6], // Estiramientos
                    ]
                ],
                'categories' => [0, 1, 4], // Calentamiento, Técnica Básica, Sparring
            ]
        ];

        $routineModels = [];
        foreach ($routines as $routineData) {
            $blocks = $routineData['blocks'];
            $categories = $routineData['categories'];
            unset($routineData['blocks'], $routineData['categories']);

            // Calculate total duration
            $totalDuration = 0;
            foreach ($blocks as $blockData) {
                foreach ($blockData['exercises'] as $exerciseIndex) {
                    $totalDuration += $exerciseModels[$exerciseIndex]->duration;
                }
            }

            $routine = Routine::create(array_merge($routineData, [
                'total_duration' => $totalDuration,
                'created_by' => $trainer->id,
            ]));

            // Attach categories
            $routine->categories()->attach(array_map(function($index) use ($categoryModels) {
                return $categoryModels[$index]->id;
            }, $categories));

            // Create blocks with exercises
            foreach ($blocks as $blockIndex => $blockData) {
                $exercises = $blockData['exercises'];
                unset($blockData['exercises']);

                $blockDuration = 0;
                foreach ($exercises as $exerciseIndex) {
                    $blockDuration += $exerciseModels[$exerciseIndex]->duration;
                }

                $block = $routine->blocks()->create(array_merge($blockData, [
                    'sort_order' => $blockIndex + 1,
                    'duration' => $blockDuration,
                ]));

                // Add exercises to block
                foreach ($exercises as $exerciseOrder => $exerciseIndex) {
                    $block->exercises()->attach($exerciseModels[$exerciseIndex]->id, [
                        'sort_order' => $exerciseOrder + 1,
                    ]);
                }
            }

            $routineModels[] = $routine;
        }

        // Create some planned classes for this week
        $plannedClasses = [
            [
                'title' => 'Clase de Principiantes',
                'description' => 'Clase grupal para nuevos miembros',
                'date' => now()->addDay()->toDateString(),
                'start_time' => '18:00',
                'end_time' => '19:30',
                'duration' => 90,
                'routine_id' => $routineModels[0]->id,
                'class_type' => 'evening',
                'max_participants' => 10,
                'target_students' => [$studentUsers[0]->id, $studentUsers[1]->id],
                'created_by' => $trainer->id,
            ],
            [
                'title' => 'Entrenamiento Cardio',
                'description' => 'Sesión intensiva de cardio',
                'date' => now()->addDays(2)->toDateString(),
                'start_time' => '19:00',
                'end_time' => '20:00',
                'duration' => 60,
                'routine_id' => $routineModels[1]->id,
                'class_type' => 'evening',
                'max_participants' => 15,
                'target_students' => array_slice(array_column($studentUsers, 'id'), 1, 3),
                'created_by' => $trainer->id,
            ],
            [
                'title' => 'Sparring Avanzado',
                'description' => 'Sesión de sparring para boxeadores experimentados',
                'date' => now()->addDays(3)->toDateString(),
                'start_time' => '20:00',
                'end_time' => '21:00',
                'duration' => 60,
                'routine_id' => $routineModels[2]->id,
                'class_type' => 'evening',
                'max_participants' => 8,
                'target_students' => array_slice(array_column($studentUsers, 'id'), 2),
                'created_by' => $trainer->id,
            ]
        ];

        foreach ($plannedClasses as $classData) {
            PlannedClass::create($classData);
        }

        echo "Database seeded successfully!\n";
        echo "================================\n";
        echo "Default accounts created:\n";
        echo "• Admin: admin@enigmaboxing.com / admin123\n";
        echo "• Trainer: trainer@enigmaboxing.com / trainer123\n";
        echo "• Students: juan.perez@email.com, maria.garcia@email.com, etc. / student123\n";
        echo "\nData created:\n";
        echo "• " . count($categories) . " categories\n";
        echo "• " . count($exercises) . " exercises\n";
        echo "• " . count($routines) . " routines\n";
        echo "• " . count($plannedClasses) . " planned classes\n";
        echo "• " . count($students) . " students with profiles\n";
    }
}
