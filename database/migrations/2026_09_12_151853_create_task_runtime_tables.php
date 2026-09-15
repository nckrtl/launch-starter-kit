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
        Schema::create('task_workspaces', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('root_task_id')->unique()->constrained('tasks')->restrictOnDelete();
            $table->string('project_id', 80);
            $table->string('source_key');
            $table->string('repository');
            $table->string('worktree')->unique();
            $table->string('base_sha', 64);
            $table->string('manifest_hash', 64);
            $table->json('configuration');
            $table->json('herdr_workspace')->nullable();
            $table->json('reviewer_session')->nullable();
            $table->json('final_check')->nullable();
            $table->json('final_result')->nullable();
            $table->text('attention')->nullable();
            $table->timestamps();
            $table->unique(['project_id', 'source_key']);
        });
        Schema::create('task_agent_dispatches', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_run_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('step_key');
            $table->string('kind', 30);
            $table->unsignedInteger('round')->default(0);
            $table->string('state', 30)->default('prepared')->index();
            $table->string('execution_key')->nullable()->index();
            $table->string('token_hash', 64);
            $table->text('handoff_token');
            $table->text('prompt');
            $table->json('session')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['task_workspace_id', 'step_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_agent_dispatches');
        Schema::dropIfExists('task_workspaces');
    }
};
