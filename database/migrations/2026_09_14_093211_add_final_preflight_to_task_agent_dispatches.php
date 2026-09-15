<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_agent_dispatches', function (Blueprint $table): void {
            $table->unsignedTinyInteger('final_preflight_version')->nullable();
            $table->json('final_preflight')->nullable();
        });
        Schema::table('task_final_continuations', function (Blueprint $table): void {
            $table->json('previous_manifest')->nullable();
            $table->json('manifest')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('task_agent_dispatches')->whereNotNull('final_preflight_version')->exists()
            || DB::table('task_final_continuations')->whereNotNull('manifest')->exists()) {
            throw new LogicException('Retained final preflight and manifest evidence requires a forward fix.');
        }
        Schema::table('task_final_continuations', function (Blueprint $table): void {
            $table->dropColumn(['previous_manifest', 'manifest']);
        });
        Schema::table('task_agent_dispatches', function (Blueprint $table): void {
            $table->dropColumn(['final_preflight_version', 'final_preflight']);
        });
    }
};
