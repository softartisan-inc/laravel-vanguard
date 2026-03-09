<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vanguard_backups', function (Blueprint $table) {
            $table->id();
            $table->string('tenant_id')->nullable()->index(); // null = landlord backup
            $table->string('type');       // landlord | tenant | filesystem
            $table->string('status');     // pending | running | completed | failed

            $table->json('sources')->nullable();       // ['database', 'filesystem']
            $table->json('destinations')->nullable();  // ['local', 'remote']

            $table->string('file_path')->nullable();   // Local relative path
            $table->string('remote_path')->nullable(); // Remote disk path
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('checksum', 64)->nullable(); // SHA-256

            $table->text('error')->nullable();
            $table->json('meta')->nullable(); // extra driver info, options

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vanguard_backups');
    }
};
