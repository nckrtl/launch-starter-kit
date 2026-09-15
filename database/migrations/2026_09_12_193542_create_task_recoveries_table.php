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
        Schema::create('task_recoveries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('root_task_id')->unique()->constrained('tasks')->restrictOnDelete();
            $table->text('evidence_path');
            $table->string('evidence_sha256', 64);
            $table->text('source_path');
            $table->string('source_sha256', 64);
            $table->text('backup_path');
            $table->string('backup_sha256', 64);
            $table->json('provenance');
            $table->timestamp('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_recoveries');
    }
};
