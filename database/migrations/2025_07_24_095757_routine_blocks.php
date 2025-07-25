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
        // Routine Categories Pivot Table
        Schema::create('routine_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->onDelete('cascade');
            $table->foreignId('category_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            $table->unique(['routine_id', 'category_id']);
        });

        // Routine Blocks Table
        Schema::create('routine_blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('color', 7)->default('#3B82F6'); // Hex color
            $table->text('notes')->nullable();
            $table->integer('sort_order')->default(0);
            $table->integer('duration')->default(0); // calculated from exercises
            $table->timestamps();

            $table->index(['routine_id', 'sort_order']);
        });

        // Routine Block Exercises (Links exercises to blocks)
        Schema::create('routine_block_exercises', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_block_id')->constrained()->onDelete('cascade');
            $table->foreignId('exercise_id')->constrained()->onDelete('cascade');
            $table->integer('sort_order')->default(0);
            $table->integer('duration_override')->nullable(); // Override exercise duration
            $table->json('exercise_notes')->nullable(); // Specific notes for this instance
            $table->json('custom_timers')->nullable(); // Override multi-timer settings
            $table->timestamps();

            $table->index(['routine_block_id', 'sort_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routine_block_exercises');
        Schema::dropIfExists('routine_blocks');
        Schema::dropIfExists('routine_categories');
    }
};
