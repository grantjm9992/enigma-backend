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
        Schema::create('exercises', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('duration'); // in minutes
            $table->enum('intensity', ['low', 'medium', 'high'])->default('medium');
            $table->enum('work_type', [
                'strength', 'coordination', 'reaction', 'technique',
                'cardio', 'flexibility', 'sparring', 'conditioning'
            ])->default('technique');
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced'])->default('intermediate');
            $table->json('tags')->nullable(); // Array of tags
            $table->json('materials')->nullable(); // Array of required materials
            $table->json('protection')->nullable(); // Array of required protection
            $table->json('instructions')->nullable(); // Step-by-step instructions
            $table->string('video_url')->nullable(); // Optional video URL
            $table->string('image_url')->nullable(); // Optional image URL

            // Multi-timer support
            $table->boolean('is_multi_timer')->default(false);
            $table->json('timers')->nullable(); // Multi-timer configuration

            // Exercise settings
            $table->boolean('is_template')->default(false);
            $table->boolean('is_active')->default(true);
            $table->enum('visibility', ['private', 'shared', 'public'])->default('private');

            // Metadata
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->integer('usage_count')->default(0); // Track how often used
            $table->decimal('average_rating', 3, 2)->nullable(); // Average user rating

            $table->timestamps();

            $table->index(['work_type', 'difficulty', 'is_active']);
            $table->index(['created_by', 'visibility']);
            $table->index('is_template');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exercises');
    }
};
