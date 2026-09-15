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
        Schema::create('task_manifest_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_task_run_id')->index()->constrained('task_runs')->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('target_task_id')->unique()->constrained('tasks')->restrictOnDelete();
            $table->unsignedInteger('sequence');
            $table->string('amendment_key', 100);
            $table->string('proposal_hash', 64);
            $table->string('request_hash', 64);
            $table->string('head', 64);
            $table->string('previous_manifest_hash', 64);
            $table->string('manifest_hash', 64);
            $table->json('previous_manifest');
            $table->json('manifest');
            $table->json('request');
            $table->string('audit_hash', 64);
            $table->timestamp('created_at');
            $table->unique(['task_workspace_id', 'sequence']);
            $table->unique(['task_workspace_id', 'amendment_key']);
            $table->unique(['task_workspace_id', 'previous_manifest_hash']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('task_manifest_amendments') && DB::table('task_manifest_amendments')->exists()) {
            throw new LogicException('Retain task manifest amendment history; use a reviewed forward migration once amendments exist.');
        }

        Schema::drop('task_manifest_amendments');
    }
};
