<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record which database a restore wrote to, and where it came from.
     *
     * `vanguard:restore --database=` rehearses a restore into a throwaway
     * database. Without this column such a run is indistinguishable in the
     * history from one that overwrote the target for real: the row says
     * "tenant acme, completed" either way. A history that misreports which
     * data was replaced is worse than none.
     *
     * Null means the target's own database, which is every restore the
     * endpoint performs — rehearsal stays a console decision.
     *
     * `origin` says which path asked: 'api' for the dashboard, 'console' for
     * `vanguard:restore`. Its own column rather than a prefix on
     * `requested_by`, which holds an identity and nothing else — an audit
     * reads a person there, and a channel glued in front of it is a second
     * fact in a field that already answers a question.
     */
    public function up(): void
    {
        if (! Schema::hasTable('vanguard_restores')) {
            return;
        }

        if (! Schema::hasColumn('vanguard_restores', 'target_database')) {
            Schema::table('vanguard_restores', function (Blueprint $table) {
                $table->string('target_database')->nullable()->after('source');
            });
        }

        if (! Schema::hasColumn('vanguard_restores', 'origin')) {
            // Nullable rather than defaulted: rows written before this column
            // existed came from a path nobody recorded, and stamping them
            // 'api' would be inventing history.
            Schema::table('vanguard_restores', function (Blueprint $table) {
                $table->string('origin')->nullable()->after('requested_by');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('vanguard_restores')) {
            return;
        }

        foreach (['target_database', 'origin'] as $column) {
            if (Schema::hasColumn('vanguard_restores', $column)) {
                Schema::table('vanguard_restores', function (Blueprint $table) use ($column) {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
