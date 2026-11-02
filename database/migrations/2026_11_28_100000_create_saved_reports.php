<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P8c: saved reports. A report is a type from App\Services\Reporting with its filters,
 * columns and sort, kept under a name by the person who made it, and optionally shared with the
 * business. A shared report shares its settings, never its rows: each reader sees only the
 * projects, money and time they may see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 24);
            $table->json('config');
            $table->boolean('shared')->default(false);
            $table->timestamps();
            $table->index(['team_id', 'shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_reports');
    }
};
