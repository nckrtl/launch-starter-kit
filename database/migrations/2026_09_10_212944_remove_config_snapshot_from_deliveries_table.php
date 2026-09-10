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
        if (Schema::hasColumn('deliveries', 'config_snapshot')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->dropColumn('config_snapshot');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Obsolete snapshots cannot be reconstructed after this migration runs.
    }
};
