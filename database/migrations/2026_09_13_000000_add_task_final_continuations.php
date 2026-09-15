<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_agent_dispatches', function (Blueprint $table): void {
            $table->json('final_check')->nullable();
            $table->unsignedTinyInteger('final_check_version')->nullable();
        });
        Schema::create('task_final_continuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->constrained()->restrictOnDelete();
            $table->foreignId('task_agent_dispatch_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('task_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('mode', 30);
            $table->string('request_hash', 64);
            $table->string('head', 64);
            $table->string('previous_manifest_hash', 64);
            $table->string('manifest_hash', 64);
            $table->unsignedInteger('final_round');
            $table->text('previous_attention');
            $table->json('previous_result')->nullable();
            $table->json('request');
            $table->timestamp('created_at');
            $table->unique(['task_workspace_id', 'final_round']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_final_continuations');
        Schema::table('task_agent_dispatches', function (Blueprint $table): void {
            $table->dropColumn(['final_check', 'final_check_version']);
        });
    }
};
