<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P8d: custom dashboards. A dashboard belongs to the person who made it and may be shared with
 * the business; its widgets (a report, health, the roadmap, major risks, work between projects,
 * your work, your time) are worked out for whoever looks, so sharing shares the arrangement and
 * never the rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->boolean('shared')->default(false);
            $table->timestamps();
            $table->index(['team_id', 'shared']);
        });

        Schema::create('dashboard_widgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dashboard_id')->constrained('dashboards')->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('title', 80)->nullable();
            $table->json('config');
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('wide')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_widgets');
        Schema::dropIfExists('dashboards');
    }
};
