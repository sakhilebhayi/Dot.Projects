<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P9c: approval steps. When a run reaches one, a row is written for each person who may
 * answer it; the first answer closes them all and the run carries on down the branch it chose.
 * Unanswered requests expire as rejected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('automation_runs')->cascadeOnDelete();
            $table->unsignedTinyInteger('step');
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('pending');
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['run_id', 'step', 'user_id']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_approvals');
    }
};
