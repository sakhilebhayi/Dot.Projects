<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P5c, governance: stage gates (G1) with a checklist and a formal pass, pass with conditions
 * or hold by a named approver; meetings (M-01) with attendees, agenda and minutes, steering
 * committee meetings among them; and the action register (A-01), actions from meetings or raised
 * on their own. Decisions taken at a meeting are linked to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_gates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->text('purpose')->nullable();
            $table->foreignId('milestone_id')->nullable()->constrained('milestones')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->foreignId('approver_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 12)->default('pending');
            $table->text('decision_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->foreignId('decision_id')->nullable()->constrained('project_decisions')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
        });

        Schema::create('gate_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_gate_id')->constrained('project_gates')->cascadeOnDelete();
            $table->string('body', 255);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamp('done_at')->nullable();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('project_meetings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->string('kind', 12)->default('team');
            $table->dateTime('held_at');
            $table->string('location', 255)->nullable();
            $table->string('guests', 500)->nullable();
            $table->text('agenda')->nullable();
            $table->text('minutes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'held_at']);
        });

        Schema::create('meeting_attendees', function (Blueprint $table) {
            $table->foreignId('project_meeting_id')->constrained('project_meetings')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['project_meeting_id', 'user_id']);
        });

        Schema::create('project_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->string('title', 255);
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('due_date')->nullable();
            $table->string('status', 8)->default('open');
            $table->foreignId('meeting_id')->nullable()->constrained('project_meetings')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['project_id', 'number']);
            $table->index(['project_id', 'status']);
        });

        Schema::table('project_decisions', function (Blueprint $table) {
            $table->foreignId('meeting_id')->nullable()->constrained('project_meetings')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('meeting_id');
        });
        Schema::dropIfExists('project_actions');
        Schema::dropIfExists('meeting_attendees');
        Schema::dropIfExists('project_meetings');
        Schema::dropIfExists('gate_checks');
        Schema::dropIfExists('project_gates');
    }
};
