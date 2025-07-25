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
        // Routine Completions (Sessions)
        Schema::create('routine_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_id')->constrained()->onDelete('cascade');
            $table->string('routine_name'); // Store name at time of completion
            $table->foreignId('category_id')->nullable()->constrained()->onDelete('set null');
            $table->string('category_name')->nullable(); // Store name at time of completion

            // Session details
            $table->timestamp('completed_at');
            $table->integer('planned_duration'); // original routine duration
            $table->integer('actual_duration'); // actual session duration in minutes
            $table->json('notes')->nullable(); // Session notes
            $table->tinyInteger('rating')->unsigned()->nullable(); // 1-5 rating

            // Session type
            $table->boolean('morning_session')->default(false);
            $table->boolean('afternoon_session')->default(false);
            $table->boolean('is_full_day_complete')->default(false);

            // Completion data
            $table->json('block_completions')->nullable(); // Detailed block completion data
            $table->json('exercise_completions')->nullable(); // Detailed exercise completion data

            // Metadata
            $table->foreignId('completed_by')->constrained('users')->onDelete('cascade'); // Trainer who ran session
            $table->timestamps();

            $table->index(['routine_id', 'completed_at']);
            $table->index(['completed_at', 'morning_session', 'afternoon_session'], 'routine_m_a_completed_at');
            $table->index(['completed_by', 'completed_at']);
        });

        // Session Attendees (Students who participated)
        Schema::create('routine_completion_attendees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('routine_completion_id')->constrained()->onDelete('cascade');
            $table->foreignId('student_id')->constrained('users')->onDelete('cascade');
            $table->integer('participation_minutes')->default(0); // How long they participated
            $table->json('performance_notes')->nullable(); // Notes about student performance
            $table->boolean('completed_full_session')->default(true);
            $table->timestamps();

            $table->unique(['routine_completion_id', 'student_id'], 'rca_rc_s');
            $table->index(['student_id', 'created_at']);
        });

        // Planned Classes (Future sessions)
        Schema::create('planned_classes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('date');
            $table->time('start_time');
            $table->time('end_time');
            $table->integer('duration'); // in minutes

            // Linked routine (optional)
            $table->foreignId('routine_id')->nullable()->constrained()->onDelete('set null');

            // Class details
            $table->enum('class_type', ['morning', 'afternoon', 'evening', 'custom'])->default('custom');
            $table->integer('max_participants')->nullable();
            $table->json('target_students')->nullable(); // Array of student IDs
            $table->json('materials_needed')->nullable();
            $table->json('notes')->nullable();

            // Status
            $table->enum('status', ['planned', 'in_progress', 'completed', 'cancelled'])->default('planned');
            $table->foreignId('routine_completion_id')->nullable()->constrained()->onDelete('set null'); // Link to completion when done

            // Metadata
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->string('notion_page_id')->nullable(); // For Notion sync

            $table->timestamps();

            $table->index(['date', 'start_time']);
            $table->index(['created_by', 'status']);
            $table->index(['routine_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('planned_classes');
        Schema::dropIfExists('routine_completion_attendees');
        Schema::dropIfExists('routine_completions');
    }
};
