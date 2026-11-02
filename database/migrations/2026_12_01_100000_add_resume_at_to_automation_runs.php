<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P9b: a run that reached a "wait" step rests until resume_at; the hourly sweep picks it up
 * then and carries on with the steps after the wait.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->timestamp('resume_at')->nullable()->after('attempts');
            $table->index(['status', 'resume_at']);
        });
    }

    public function down(): void
    {
        Schema::table('automation_runs', function (Blueprint $table) {
            $table->dropIndex(['status', 'resume_at']);
            $table->dropColumn('resume_at');
        });
    }
};
