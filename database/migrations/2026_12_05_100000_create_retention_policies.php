<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P12b: how long a business keeps what accumulates: its activity history (the audit trail,
 * kept for ever unless the owner says otherwise), people's read notices and its automation run
 * history. One row per business, written by its owner or an admin; the nightly prune applies it and
 * records what it removed. A business without a row keeps the defaults in App\Services\DataRetention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('retention_policies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->unique()->constrained('teams')->cascadeOnDelete();
            $table->unsignedSmallInteger('activity_months')->nullable();
            $table->unsignedSmallInteger('notice_days');
            $table->unsignedSmallInteger('automation_days');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_run_at')->nullable();
            $table->json('last_removed')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('retention_policies');
    }
};
