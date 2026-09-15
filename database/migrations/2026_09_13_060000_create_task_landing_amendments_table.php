<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_landing_amendments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('task_workspace_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('predecessor_id')->unique()->constrained('task_landings')->restrictOnDelete();
            $table->foreignId('successor_id')->unique()->constrained('task_landings')->restrictOnDelete();
            $table->string('request_hash', 64);
            $table->json('request');
            $table->string('audit_hash', 64);
            $table->json('audit');
            $table->timestamps();
        });
        Schema::table('task_landings', function (Blueprint $table): void {
            $table->index('task_workspace_id');
            $table->dropUnique(['task_workspace_id']);
        });
    }

    public function down(): void
    {
        if (DB::table('task_landing_amendments')->exists()
            || DB::table('task_landings')->select('task_workspace_id')->groupBy('task_workspace_id')->havingRaw('COUNT(*) > 1')->exists()) {
            throw new LogicException('Retain amended landing history; use a reviewed forward migration once amendments exist.');
        }
        Schema::table('task_landings', function (Blueprint $table): void {
            $table->unique('task_workspace_id');
            $table->dropIndex(['task_workspace_id']);
        });
        Schema::drop('task_landing_amendments');
    }
};
