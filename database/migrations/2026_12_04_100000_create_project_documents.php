<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P11b: project documents. The file itself lives in the business's Dot.Files workspace; this
 * row is the project's reference to it (its Dot.Files id, the name, size and version last seen,
 * the task it belongs to), so the list needs no call to Dot.Files and nothing is stored twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->foreignId('task_id')->nullable()->constrained('project_tasks')->nullOnDelete();
            $table->uuid('files_uuid')->unique();
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('mime', 191)->nullable();
            $table->string('reference', 64)->nullable();
            $table->string('open_url', 255)->nullable();
            $table->unsignedInteger('version')->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'task_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_documents');
    }
};
