<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P9a: automation rules and their runs. A rule is a trigger (with its setting), conditions
 * and ordered actions, for one project or every project in the business, made switched off. A run
 * records one firing: the key that makes it happen once (unique per rule), each step's outcome
 * (so a retry repeats only what did not finish), and any failure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('trigger', 32);
            $table->json('params');
            $table->json('conditions');
            $table->json('actions');
            $table->boolean('active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->index(['team_id', 'active']);
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained('projects')->cascadeOnDelete();
            $table->unsignedBigInteger('activity_id')->nullable();
            $table->string('key', 190);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('what', 300)->nullable();
            $table->string('status', 10);
            $table->json('steps');
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamps();
            $table->unique(['rule_id', 'key']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('automation_rules');
    }
};
