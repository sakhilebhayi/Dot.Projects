<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P13a: project templates (a business's own, saved from its projects) and each project's methodology.
 * A template is data: the structure a new project starts with. Starter templates live in code
 * (App\Support\StarterTemplates), not here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->string('methodology', 16)->nullable();
            $table->json('structure');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('source_project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamps();
            $table->index(['team_id', 'name']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->string('methodology', 16)->nullable()->after('visibility');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('methodology');
        });
        Schema::dropIfExists('project_templates');
    }
};
