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
        Schema::create('task_recovery_resumptions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('task_recovery_id')->unique()->constrained('task_recoveries')->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->text('database_path');
            $table->string('head', 64);
            $table->string('manifest_hash', 64);
            $table->text('previous_attention');
            $table->json('reviewer_session');
            $table->json('herdr_workspace');
            $table->json('evidence');
            $table->boolean('advance_requested');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_recovery_resumptions');
    }
};
