<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vanguard_restores', function (Blueprint $table) {
            $table->id();

            // Nullable and null-on-delete: the history outlives the backup it
            // restored, so the target is copied below rather than looked up.
            $table->foreignId('backup_id')->nullable()->constrained('vanguard_backups')->nullOnDelete();

            $table->string('type');                                  // landlord | tenant | filesystem
            $table->string('tenant_id')->nullable()->index();        // null = landlord
            $table->timestamp('backup_created_at')->nullable();      // how old the archive was
            $table->string('source')->nullable();                    // local | remote | ftp

            $table->boolean('restore_db')->default(true);
            $table->boolean('restore_storage')->default(false);
            $table->boolean('verify_checksum')->default(true);

            $table->string('status')->index();                       // pending | running | completed | failed
            $table->string('phase')->nullable();
            $table->text('error')->nullable();
            $table->string('requested_by')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vanguard_restores');
    }
};
