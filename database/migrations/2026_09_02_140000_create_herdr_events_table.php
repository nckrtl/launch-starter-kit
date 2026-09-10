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
        Schema::create('herdr_events', function (Blueprint $table) {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('workspace_id');
            $table->string('workspace_label')->nullable();
            $table->string('pane_id');
            $table->string('agent')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('herdr_events');
    }
};
