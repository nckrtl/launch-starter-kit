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
        Schema::create('task_landings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('final_dispatch_id')->constrained('task_agent_dispatches')->restrictOnDelete();
            $table->uuid('issue_id');
            $table->string('candidate_sha', 40);
            $table->string('input_hash', 64);
            $table->json('request');
            $table->json('inputs');
            $table->string('artifact_ref');
            $table->string('artifact_sha', 40)->nullable();
            $table->json('package')->nullable();
            $table->string('package_hash', 64)->nullable();
            $table->string('state', 30)->default('prepared');
            $table->uuid('review_assignment')->nullable()->unique();
            $table->json('review_session')->nullable();
            $table->string('review_token_hash', 64)->nullable();
            $table->text('review_token')->nullable();
            $table->text('review_prompt')->nullable();
            $table->json('review_result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_landings');
    }
};
