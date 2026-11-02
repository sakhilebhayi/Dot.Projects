<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P6b, time: entries people log themselves (by hand or with a timer they start and stop),
 * on a project and optionally a task, billable or not; and a weekly timesheet per person per
 * business that is submitted to an approver and approved or returned. Nothing is tracked that a
 * person did not enter: no activity monitoring.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timesheets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('status', 10)->default('open');
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('return_note')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'user_id', 'week_start']);
            $table->index(['team_id', 'approver_id', 'status']);
        });

        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->date('work_date');
            $table->unsignedSmallInteger('minutes')->nullable();
            $table->timestamp('running_since')->nullable();
            $table->string('note', 500)->nullable();
            $table->boolean('billable')->default(false);
            $table->timestamps();
            $table->index(['team_id', 'user_id', 'work_date']);
            $table->index(['project_id', 'work_date']);
            $table->index(['task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('timesheets');
    }
};
