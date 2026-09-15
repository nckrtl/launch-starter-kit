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
        Schema::create('task_landing_review_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_landing_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('review_assignment')->unique();
            $table->string('request_hash', 64);
            $table->json('inputs');
            $table->json('evidence');
            $table->json('review_session');
            $table->text('reference_prompt');
            $table->string('prompt_sha256', 64);
            $table->unsignedInteger('prompt_bytes');
            $table->string('wire_sha256', 64);
            $table->unsignedInteger('wire_bytes');
            $table->string('state', 20)->default('intended');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('task_landing_review_recoveries')
            && DB::table('task_landing_review_recoveries')->exists()) {
            throw new LogicException('Retain recovery audit rows. Roll back application code only; use a reviewed forward migration for schema changes.');
        }
        Schema::dropIfExists('task_landing_review_recoveries');
    }
};
