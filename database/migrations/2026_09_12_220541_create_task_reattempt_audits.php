<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('task_reattempt_checkpoints', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->unique()->constrained()->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->json('binding');
            $table->json('observation');
            $table->json('request');
            $table->timestamp('created_at');
        });
        Schema::create('task_reattempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_reattempt_checkpoint_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_run_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->unique()->constrained()->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->json('observation');
            $table->json('request');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_reattempts');
        Schema::dropIfExists('task_reattempt_checkpoints');
    }
};
