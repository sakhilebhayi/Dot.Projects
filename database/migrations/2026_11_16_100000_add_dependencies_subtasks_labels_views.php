<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P3b: finish-to-start dependencies between tasks of one project (a task waits until every
 * task it depends on is done), one level of subtasks, labels per project, and saved views of the
 * work table per person. All additive.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('predecessor_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->foreignId('successor_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->string('type', 20)->default('finish_to_start');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['predecessor_id', 'successor_id']);
            $table->index('successor_id');
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('milestone_id')->constrained('project_tasks')->nullOnDelete();
        });

        Schema::create('project_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 40);
            $table->timestamps();
            $table->unique(['project_id', 'name']);
        });

        Schema::create('project_label_task', function (Blueprint $table) {
            $table->foreignId('project_label_id')->constrained('project_labels')->cascadeOnDelete();
            $table->foreignId('project_task_id')->constrained('project_tasks')->cascadeOnDelete();
            $table->primary(['project_label_id', 'project_task_id']);
        });

        Schema::create('saved_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 60);
            $table->json('filters');
            $table->timestamps();
            $table->index(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
        Schema::dropIfExists('project_label_task');
        Schema::dropIfExists('project_labels');
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
        });
        Schema::dropIfExists('task_dependencies');
    }
};
