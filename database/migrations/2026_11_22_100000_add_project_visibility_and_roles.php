<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R-34 P6a: project roles and private projects (owner decision, 2026-11-01: existing projects stay
 * visible to the whole business; new ones may be private, with their own members and roles).
 * A project's visibility is "team" (everyone in the business, as before) or "private" (its members,
 * its owner and the business owner). Member roles become manager, member and viewer; the unused
 * "owner" value becomes manager, since the project owner is already on the project itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('visibility', 8)->default('team');
        });

        DB::table('project_members')->where('role', 'owner')->update(['role' => 'manager']);
        Schema::table('project_members', function (Blueprint $table) {
            $table->string('role', 8)->default('member')->change();
            $table->foreignId('added_by')->nullable()->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('project_members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('added_by');
        });
        DB::table('project_members')->where('role', 'viewer')->update(['role' => 'member']);
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('visibility');
        });
    }
};
