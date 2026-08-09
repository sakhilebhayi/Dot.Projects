<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // status was created as a native enum/CHECK-constrained column.
        // Postgres needs its named CHECK constraint dropped before
        // Schema::change() can widen the column to a plain string; SQLite
        // (this app's test driver) has no such named constraint --
        // Schema::change() there rebuilds the table wholesale instead, so
        // the DROP CONSTRAINT statement is a no-op it doesn't understand.
        // Identical technique already used for Dot.Ehail's
        // driver_profiles.status.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE projects DROP CONSTRAINT IF EXISTS projects_status_check');
        }

        Schema::table('projects', function (Blueprint $table) {
            $table->string('status')->default('planning')->change();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreignId('closure_reviewed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->timestamp('closure_reviewed_at')->nullable()->after('closure_reviewed_by');
            $table->text('closure_rejected_reason')->nullable()->after('closure_reviewed_at');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignId('closure_reviewed_by');
            $table->dropColumn(['closure_reviewed_at', 'closure_rejected_reason']);
        });
    }
};
