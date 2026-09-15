<?php

declare(strict_types=1);

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
        Schema::create('task_artifact_review_bindings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_run_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_run_review_id')->unique()->constrained()->restrictOnDelete();
            $table->unsignedInteger('round');
            $table->string('binding_hash', 64);
            $table->json('binding');
            $table->timestamp('created_at');
            $table->unique(['task_run_id', 'round']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('task_artifact_review_bindings') && DB::table('task_artifact_review_bindings')->exists()) {
            throw new LogicException('Retain artifact review audits; use a reviewed forward migration after any binding is recorded.');
        }
        Schema::dropIfExists('task_artifact_review_bindings');
    }
};
