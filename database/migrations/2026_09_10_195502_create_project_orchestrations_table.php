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
        Schema::create('project_orchestrations', function (Blueprint $table) {
            $table->id();
            $table->string('manifest_project_id', 80)->unique();
            $table->json('config');
            $table->string('state')->default('enabled')->index();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('project_orchestrations');
    }
};
