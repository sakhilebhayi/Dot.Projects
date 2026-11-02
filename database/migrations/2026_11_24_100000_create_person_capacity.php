<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P6d: how much project time a person has in a week, per business. Without a row, a person
 * has the standard 40 hours. Set by the business owner or an admin; leave and public holidays come
 * from Dot.HR once it offers them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('person_capacity', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('minutes_per_week');
            $table->string('note', 120)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('person_capacity');
    }
};
