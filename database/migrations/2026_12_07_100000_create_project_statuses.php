<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P13c: a project's own task statuses. Each belongs to one of the five fixed categories that every rule reads
 * (project_tasks.status keeps the category); a task's status_id names which of the project's statuses it is in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('name', 40);
            $table->string('category', 12);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['project_id', 'sort_order']);
        });

        Schema::table('project_tasks', function (Blueprint $table) {
            $table->foreignId('status_id')->nullable()->after('status')->constrained('project_statuses')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('status_id');
        });
        Schema::dropIfExists('project_statuses');
    }
};
