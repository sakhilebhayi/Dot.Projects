<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P4, the schedule: a baseline is a numbered snapshot of the plan (each task's start and due
 * date, each milestone's due date, the project's due date) taken when the plan is agreed, so later
 * slippage can be measured against it. Snapshots are never edited; a new plan is a new number.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_baselines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('baseline_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_baseline_id')->constrained('project_baselines')->cascadeOnDelete();
            $table->string('item_type', 12); // task | milestone | project
            $table->unsignedBigInteger('item_id');
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->index(['project_baseline_id', 'item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('baseline_items');
        Schema::dropIfExists('project_baselines');
    }
};
