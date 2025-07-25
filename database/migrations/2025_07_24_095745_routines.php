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
        Schema::create('routines', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->text('objective')->nullable(); // Session objective
            $table->integer('total_duration'); // in minutes
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced'])->default('intermediate');
            $table->enum('level', ['principiante', 'intermedio', 'avanzado'])->default('intermedio'); // Spanish levels
            $table->json('tags')->nullable(); // Array of tags
            $table->json('materials')->nullable(); // Required materials with categories
            $table->json('protection')->nullable(); // Required protection equipment

            // Settings
            $table->boolean('is_template')->default(false);
            $table->boolean('is_favorite')->default(false);
            $table->enum('visibility', ['private', 'shared', 'public'])->default('private');
            $table->boolean('is_active')->default(true);

            // Scheduling
            $table->integer('repeat_in_days')->default(0); // Auto-repeat interval
            $table->json('scheduled_days')->nullable(); // Days of week for scheduling

            // Metadata
            $table->text('trainer_notes')->nullable();
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->integer('usage_count')->default(0); // Track how often used
            $table->decimal('average_rating', 3, 2)->nullable(); // Average session rating

            // Integration
            $table->string('notion_page_id')->nullable(); // For Notion sync

            $table->timestamps();

            $table->index(['created_by', 'visibility', 'is_active']);
            $table->index(['difficulty', 'level']);
            $table->index('is_template');
            $table->index('is_favorite');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('routines');
    }
};
