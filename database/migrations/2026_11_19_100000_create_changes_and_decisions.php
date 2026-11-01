<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P5b, change control: a change request (CR-01) states what changes and why, assesses its
 * impact on time, money, scope, people and risk, and is approved or rejected by a named approver.
 * An approved change can re-baseline the plan. The decision register (D-01) keeps every decision
 * with its context, the options weighed and who decided; approving a change records one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->text('context')->nullable();
            $table->text('options')->nullable();
            $table->text('outcome')->nullable();
            $table->string('status', 12)->default('proposed');
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('decide_by')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->date('decided_on')->nullable();
            $table->foreignId('superseded_by')->nullable()->constrained('project_decisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::create('project_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->string('kind', 12)->default('scope');
            $table->text('reason')->nullable();
            $table->text('description')->nullable();
            $table->integer('schedule_days')->nullable();
            $table->bigInteger('cost_cents')->nullable();
            $table->text('scope_impact')->nullable();
            $table->text('resource_impact')->nullable();
            $table->text('risk_impact')->nullable();
            $table->string('status', 12)->default('draft');
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->boolean('self_approved')->default(false);
            $table->timestamp('implemented_at')->nullable();
            $table->foreignId('baseline_id')->nullable()->constrained('project_baselines')->nullOnDelete();
            $table->foreignId('decision_id')->nullable()->constrained('project_decisions')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_changes');
        Schema::dropIfExists('project_decisions');
    }
};
