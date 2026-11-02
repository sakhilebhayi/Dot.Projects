<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P8a: portfolios and programmes. A portfolio groups a business's projects under an owner and
 * a strategic objective; its kind says what it is (portfolio, programme, department, business unit
 * or strategic initiative). A project can sit in several, for example a programme and a department.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portfolios', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->string('name', 160);
            $table->string('kind', 12)->default('portfolio');
            $table->text('objective')->nullable();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('portfolio_project', function (Blueprint $table) {
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->primary(['portfolio_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portfolio_project');
        Schema::dropIfExists('portfolios');
    }
};
