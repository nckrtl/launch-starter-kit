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
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 80);
            $table->foreignId('parent_id')->nullable()->constrained('tasks')->noActionOnDelete();
            $table->string('kind', 30)->default('executable');
            $table->string('title');
            $table->text('description');
            $table->string('status', 30)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index('parent_id');
        });

        Schema::create('task_dependencies', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('depends_on_task_id')->constrained('tasks')->restrictOnDelete();

            $table->primary(['task_id', 'depends_on_task_id']);
            $table->index('depends_on_task_id');
        });

        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->restrictOnDelete();
            $table->foreignId('active_task_id')->nullable()->unique()->constrained('tasks')->restrictOnDelete();
            $table->foreignId('root_task_id')->constrained('tasks')->restrictOnDelete();
            $table->foreignId('active_root_task_id')->nullable()->unique()->constrained('tasks')->restrictOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('idempotency_key', 100);
            $table->string('worker_ref');
            $table->string('reviewer_ref');
            $table->string('base_sha', 64)->nullable();
            $table->string('status', 30)->default('running');
            $table->json('input');
            $table->json('output')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['task_id', 'attempt']);
            $table->unique(['task_id', 'idempotency_key']);
            $table->index(['status', 'started_at']);
            $table->index(['root_task_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_runs');
        Schema::dropIfExists('task_dependencies');
        Schema::dropIfExists('tasks');
    }
};
