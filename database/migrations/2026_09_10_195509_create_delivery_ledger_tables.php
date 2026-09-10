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
        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_orchestration_id')->constrained()->restrictOnDelete();
            $table->string('external_issue_provider', 40);
            $table->string('external_issue_id', 100);
            $table->string('external_issue_key', 100)->nullable();
            $table->string('workflow_type', 80);
            $table->unsignedInteger('workflow_version');
            $table->json('config_snapshot');
            $table->string('status')->default('queued');
            $table->string('current_phase', 100);
            $table->char('active_issue_key', 64)->nullable()->unique();
            $table->string('branch')->nullable();
            $table->string('worktree_path')->nullable();
            $table->string('candidate_sha', 64)->nullable();
            $table->unsignedBigInteger('pull_request_number')->nullable();
            $table->string('pull_request_url')->nullable();
            $table->json('completion_details')->nullable();
            $table->json('failure_details')->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index(['external_issue_provider', 'external_issue_id']);
            $table->index(['status', 'created_at']);
            $table->index(['project_orchestration_id', 'status']);
        });

        Schema::create('phase_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->string('phase_name', 100);
            $table->unsignedInteger('attempt');
            $table->string('status')->default('pending');
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->string('current_block', 100)->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->json('failure_details')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->unique(['delivery_id', 'phase_name', 'attempt']);
            $table->index(['delivery_id', 'status']);
        });

        Schema::create('agent_dispatches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_run_id')->constrained()->restrictOnDelete();
            $table->string('agent_role', 80);
            $table->string('idempotency_key')->unique();
            $table->string('herdr_session')->nullable();
            $table->string('herdr_workspace_id')->nullable();
            $table->string('herdr_tab_id')->nullable();
            $table->string('herdr_pane_id')->nullable();
            $table->string('herdr_terminal_id')->nullable();
            $table->string('herdr_agent_id')->nullable();
            $table->string('herdr_agent_name')->nullable();
            $table->string('prompt_name');
            $table->unsignedInteger('prompt_version');
            $table->string('prompt_hash', 64);
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('state_change_seq')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->timestamps();

            $table->unique(['phase_run_id', 'agent_role']);
            $table->index(['herdr_session', 'herdr_pane_id', 'status'], 'dispatches_herdr_correlation_index');
            $table->unique(['herdr_session', 'herdr_pane_id'], 'dispatches_herdr_pane_unique');
        });

        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('phase_run_id')->constrained()->restrictOnDelete();
            $table->string('kind', 80);
            $table->unsignedInteger('schema_version');
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->string('candidate_sha', 64)->nullable();
            $table->string('validation_status')->default('pending');
            $table->json('validation_errors')->nullable();
            $table->timestamp('captured_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamps();

            $table->unique(['phase_run_id', 'kind']);
            $table->index(['validation_status', 'captured_at']);
        });

        Schema::create('external_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('ingestion_id')->unique();
            $table->string('provider', 40);
            $table->string('provider_event_id')->nullable();
            $table->string('event_kind', 100);
            $table->json('payload');
            $table->char('payload_hash', 64);
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('agent_dispatch_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_event_id']);
            $table->index(['provider', 'event_kind', 'received_at']);
            $table->index(['processed_at', 'failed_at']);
        });

        Schema::create('maintenance_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_orchestration_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 80);
            $table->string('status')->default('pending');
            $table->unsignedInteger('attempt')->default(1);
            $table->string('idempotency_key')->unique();
            $table->json('input')->nullable();
            $table->json('result')->nullable();
            $table->string('failure_code', 100)->nullable();
            $table->text('failure_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['project_orchestration_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('maintenance_runs');
        Schema::dropIfExists('external_events');
        Schema::dropIfExists('receipts');
        Schema::dropIfExists('agent_dispatches');
        Schema::dropIfExists('phase_runs');
        Schema::dropIfExists('deliveries');
    }
};
