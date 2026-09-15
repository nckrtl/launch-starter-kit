<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_workspaces', function (Blueprint $table): void {
            $table->unsignedBigInteger('orbit_node_id')->nullable()->after('configuration');
            $table->unsignedBigInteger('orbit_herdr_session_id')->nullable()->after('orbit_node_id');
            $table->string('orbit_herdr_session')->nullable()->after('orbit_herdr_session_id');
            $table->string('orbit_herdr_observer_origin')->nullable()->after('orbit_herdr_session');
            $table->index(['orbit_node_id', 'orbit_herdr_session_id']);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('task_workspaces')) {
            return;
        }

        Schema::table('task_workspaces', function (Blueprint $table): void {
            if (Schema::hasIndex('task_workspaces', ['orbit_node_id', 'orbit_herdr_session_id'])) {
                $table->dropIndex(['orbit_node_id', 'orbit_herdr_session_id']);
            }

            $columns = array_values(array_filter([
                'orbit_node_id', 'orbit_herdr_session_id', 'orbit_herdr_session', 'orbit_herdr_observer_origin',
            ], static fn (string $column): bool => Schema::hasColumn('task_workspaces', $column)));
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
