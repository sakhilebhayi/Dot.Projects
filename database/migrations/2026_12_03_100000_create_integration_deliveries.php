<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P11a: the outbox for calls to other Dot platforms. Each call is written here first (never
 * sent inside a person's request), keyed so the same thing is never sent twice, and delivered by
 * the scheduler every minute with retries and backoff. Failures stay visible on the Integrations
 * page with their reason. The payload is encrypted at rest and pruned 30 days after delivery.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('integration', 20);
            $table->foreignId('team_id')->nullable()->constrained('teams')->cascadeOnDelete();
            $table->string('event', 80);
            $table->string('key', 190);
            $table->text('payload');
            $table->string('status', 10)->default('pending');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('next_attempt_at')->nullable();
            $table->unsignedSmallInteger('last_status')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['integration', 'key']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['team_id', 'integration', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_deliveries');
    }
};
