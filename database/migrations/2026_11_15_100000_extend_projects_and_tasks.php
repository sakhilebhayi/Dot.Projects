<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P3a, the execution core: projects can be archived (read-only, restorable), tasks carry a
 * start date, an effort estimate and when they were finished, and a task can hold a checklist.
 * All additive; nothing existing changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('closure_rejected_reason');
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('priority');
            $table->unsignedInteger('estimate_minutes')->nullable()->after('due_date');
            $table->timestamp('completed_at')->nullable()->after('estimate_minutes');
        });

        Schema::create('task_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('body', 255);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_task_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_checklist_items');
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropColumn(['start_date', 'estimate_minutes', 'completed_at']);
        });
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn('archived_at');
        });
    }
};
