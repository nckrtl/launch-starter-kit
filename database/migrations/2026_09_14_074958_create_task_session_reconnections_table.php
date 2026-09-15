<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_session_reconnections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->unique()->constrained()->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->json('request');
            $table->json('binding');
            $table->json('sessions');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('task_session_reconnections') && DB::table('task_session_reconnections')->exists()) {
            throw new LogicException('Retain session reconnection audits; roll back code only or use a reviewed forward migration.');
        }
        Schema::dropIfExists('task_session_reconnections');
    }
};
