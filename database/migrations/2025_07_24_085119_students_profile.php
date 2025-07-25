<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('student_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->integer('age')->nullable();
            $table->decimal('height', 8, 2)->nullable(); // in cm
            $table->decimal('weight', 8, 2)->nullable(); // in kg
            $table->timestamp('last_weight_update')->nullable();
            $table->enum('level', ['principiante', 'intermedio', 'avanzado', 'competidor', 'elite'])->default('principiante');
            $table->json('strengths')->nullable(); // Array of strengths
            $table->json('weaknesses')->nullable(); // Array of weaknesses
            $table->text('notes')->nullable();
            $table->text('tactical_notes')->nullable();
            $table->timestamp('last_tactical_notes_update')->nullable();
            $table->json('pending_notes')->nullable(); // Array of pending notes
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_profiles');
    }
};
