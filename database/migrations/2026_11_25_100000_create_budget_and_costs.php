<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P7a, the cost ledger. A project's budget is a set of lines by category; every change to a
 * line's amount is a revision with a reason (and, where it comes from one, the approved change
 * request). Costs are commitments (orders and contracts placed) and actuals (invoices and
 * expenses), recorded by people with a supplier and reference; an actual may settle a commitment.
 * Amounts are whole cents, in rand. Nothing is computed from guesses: a line's cost to complete is
 * what is left of its budget unless someone sets it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('category', 12);
            $table->string('name', 160);
            $table->bigInteger('budget_cents')->default(0);
            $table->bigInteger('to_complete_cents')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('budget_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->bigInteger('from_cents');
            $table->bigInteger('to_cents');
            $table->string('reason', 500);
            $table->foreignId('change_id')->nullable()->constrained('project_changes')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('cost_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('budget_line_id')->constrained('budget_lines')->cascadeOnDelete();
            $table->string('kind', 10); // commitment | actual
            $table->string('description', 255);
            $table->string('supplier', 160)->nullable();
            $table->string('reference', 80)->nullable();
            $table->bigInteger('amount_cents');
            $table->date('dated');
            $table->foreignId('settles_id')->nullable()->constrained('cost_items')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cost_items');
        Schema::dropIfExists('budget_revisions');
        Schema::dropIfExists('budget_lines');
    }
};
