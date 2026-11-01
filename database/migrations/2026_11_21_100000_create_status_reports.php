<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P5d, status reports: a periodic report (SR-01) with the manager's rating and words, and the
 * facts of the project at the moment it was published, frozen as a snapshot so the record never
 * changes after it is sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('period_from');
            $table->date('period_to');
            $table->string('overall', 10)->nullable();
            $table->text('summary')->nullable();
            $table->text('achievements')->nullable();
            $table->text('next_steps')->nullable();
            $table->text('help_needed')->nullable();
            $table->string('status', 10)->default('draft');
            $table->json('snapshot')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_reports');
    }
};
