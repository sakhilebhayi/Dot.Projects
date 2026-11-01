<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P5a, governance: a risk register and an issue log per project. A risk is something that may
 * happen, rated by probability and impact on the project's own matrix (configurable, stored on the
 * project); an issue is something that has happened and needs a corrective action. Both are
 * numbered per project (R-01, I-01) and their history is the activity log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->json('risk_matrix')->nullable();
        });

        Schema::create('project_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 20)->default('other');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('probability');
            $table->unsignedTinyInteger('impact');
            $table->unsignedTinyInteger('residual_probability')->nullable();
            $table->unsignedTinyInteger('residual_impact')->nullable();
            $table->text('mitigation')->nullable();
            $table->text('contingency')->nullable();
            $table->string('status', 16)->default('open');
            $table->date('review_date')->nullable();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('escalation_note', 500)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->text('description')->nullable();
            $table->string('category', 20)->default('other');
            $table->string('severity', 10)->default('medium');
            $table->string('priority', 10)->default('medium');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 16)->default('open');
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->date('target_date')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('risk_id')->nullable()->constrained('project_risks')->nullOnDelete();
            $table->timestamp('escalated_at')->nullable();
            $table->foreignId('escalated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('escalation_note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_issues');
        Schema::dropIfExists('project_risks');
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('risk_matrix');
        });
    }
};
