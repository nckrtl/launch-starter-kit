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
        Schema::table('task_runs', function (Blueprint $table) {
            $table->string('commit_sha', 64)->nullable();
        });

        Schema::create('task_run_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_run_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('round');
            $table->string('tree_sha', 64)->nullable();
            $table->json('output');
            $table->timestamp('requested_at');
            $table->string('verdict', 30)->nullable();
            $table->text('summary')->nullable();
            $table->text('evidence_ref')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['task_run_id', 'round']);
        });

        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('accepted_task_run_id')->nullable()->constrained('task_runs')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('accepted_task_run_id');
        });

        Schema::dropIfExists('task_run_reviews');

        Schema::table('task_runs', function (Blueprint $table) {
            $table->dropColumn('commit_sha');
        });
    }
};
