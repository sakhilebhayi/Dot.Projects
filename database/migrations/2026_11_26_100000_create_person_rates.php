<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P7b: hourly cost rates per person per business, dated, so time is costed at the rate in
 * force on the day it was worked; and the budget line a project charges its approved time to.
 * Rates are seen and set only by the business owner and admins.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('hourly_cents');
            $table->date('effective_from');
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'user_id', 'effective_from']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('labour_line_id')->nullable()->constrained('budget_lines')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('labour_line_id');
        });
        Schema::dropIfExists('person_rates');
    }
};
