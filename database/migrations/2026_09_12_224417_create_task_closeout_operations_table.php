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
        Schema::create('task_closeout_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_landing_id')->constrained()->restrictOnDelete();
            $table->string('operation', 100);
            $table->uuid('assignment')->unique();
            $table->string('package_hash', 64);
            $table->json('input');
            $table->string('input_hash', 64);
            $table->string('state', 20)->default('prepared');
            $table->json('preflight')->nullable();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['task_landing_id', 'operation']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_closeout_operations');
    }
};
