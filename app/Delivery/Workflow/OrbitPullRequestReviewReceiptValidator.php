<?php

declare(strict_types=1);

namespace App\Delivery\Workflow;

use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Models\AgentDispatch;
use App\Models\Delivery;
use App\Models\PhaseRun;
use App\Models\Receipt;

final readonly class OrbitPullRequestReviewReceiptValidator
{
    public function __construct(
        private OrbitFeatureWorkflow $workflow,
        private OrbitPullRequestReviewSourceValidator $sources,
    ) {}

    public function matches(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $receipt,
        bool $retainedTransition = false,
    ): bool {
        return $receipt->phase_run_id === $phase->id
            && $receipt->kind === 'orbit_pr_review'
            && $receipt->schema_version === 1
            && $receipt->validation_status === ReceiptValidationStatus::Valid
            && $receipt->validated_at !== null
            && hash_equals(
                $receipt->payload_hash,
                hash('sha256', json_encode($receipt->payload, JSON_THROW_ON_ERROR)),
            )
            && $receipt->candidate_sha === ($receipt->payload['candidate_sha'] ?? null)
            && $this->matchesPayload(
                $delivery,
                $phase,
                $dispatch,
                $receipt->payload,
                $retainedTransition,
            );
    }

    public function matchesInput(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        bool $retainedTransition = false,
    ): bool {
        $source = $this->sources->sourceReceipt($delivery, $phase, $retainedTransition);
        $pullRequest = $this->pullRequestInput($phase);

        return $source !== null
            && $pullRequest !== null
            && $this->matchesDispatch(
                $delivery,
                $phase,
                $dispatch,
                $source,
                $pullRequest,
                $retainedTransition,
            );
    }

    /** @param array<string, mixed> $payload */
    public function matchesPayload(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        array $payload,
        bool $retainedTransition = false,
    ): bool {
        $source = $this->sources->sourceReceipt($delivery, $phase, $retainedTransition);
        $sourcePayload = $source?->payload;
        $artifact = is_array($sourcePayload) ? ($sourcePayload['artifact_sha'] ?? null) : null;
        $gate = is_array($sourcePayload) ? ($sourcePayload['gate_receipt_path'] ?? null) : null;
        $pullRequest = $this->pullRequestInput($phase);
        $allowed = [
            'kind', 'schema_version', 'delivery_id', 'dispatch_id', 'issue_key', 'phase', 'attempt',
            'result', 'worktree', 'candidate_sha', 'handoff_path', 'handoff', 'artifact_sha',
            'pull_request_body_path', 'pull_request_body', 'pull_request_body_sha256',
        ];

        return $source !== null
            && is_string($artifact)
            && is_string($gate)
            && is_array($pullRequest)
            && array_diff(array_keys($payload), $allowed) === []
            && count($payload) === count($allowed)
            && ($payload['kind'] ?? null) === 'orbit_pr_review'
            && ($payload['schema_version'] ?? null) === 1
            && ($payload['delivery_id'] ?? null) === $delivery->id
            && ($payload['dispatch_id'] ?? null) === $dispatch->id
            && ($payload['issue_key'] ?? null) === $delivery->external_issue_key
            && ($payload['phase'] ?? null) === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && ($payload['attempt'] ?? null) === $phase->attempt
            && in_array($payload['result'] ?? null, ['approved', 'changes', 'blocked'], true)
            && ($payload['worktree'] ?? null) === $delivery->worktree_path
            && ($payload['candidate_sha'] ?? null) === $delivery->candidate_sha
            && ($payload['candidate_sha'] ?? null) === ($sourcePayload['candidate_sha'] ?? null)
            && ($payload['artifact_sha'] ?? null) === $artifact
            && is_string($payload['handoff_path'] ?? null)
            && str_starts_with($payload['handoff_path'], '.loop/')
            && is_string($payload['handoff'] ?? null)
            && trim($payload['handoff']) !== ''
            && $this->matchesInput($delivery, $phase, $dispatch, $retainedTransition)
            && $this->matchesBody(
                $delivery,
                $payload,
                $artifact,
                $gate,
                $sourcePayload['flow'] ?? null,
            );
    }

    /** @param array<string, mixed> $pullRequest */
    private function matchesDispatch(
        Delivery $delivery,
        PhaseRun $phase,
        AgentDispatch $dispatch,
        Receipt $source,
        array $pullRequest,
        bool $retainedTransition,
    ): bool {
        $config = $delivery->projectOrchestration->config;
        $expectedPrompt = $this->workflow->pullRequestReviewPrompt(
            (string) $delivery->external_issue_key,
            (string) $delivery->worktree_path,
            $delivery->id,
            $phase->id,
            $dispatch->id,
            sprintf(
                '%s %s delivery:submit-orbit-pr-review-receipt %d %d',
                escapeshellarg(PHP_BINARY),
                escapeshellarg(base_path('artisan')),
                $phase->id,
                $dispatch->id,
            ),
            $source->payload,
            $pullRequest,
        );

        return $phase->delivery_id === $delivery->id
            && $phase->phase_name === OrbitFeatureWorkflow::PR_REVIEW_PHASE
            && $phase->attempt >= 1
            && $phase->agentDispatches()->count() === 1
            && $dispatch->phase_run_id === $phase->id
            && $dispatch->agent_role === OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
            && $dispatch->idempotency_key === IdempotencyKey::forDispatch(
                $delivery->id,
                OrbitFeatureWorkflow::PR_REVIEW_PHASE,
                $phase->attempt,
                OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE,
            )->value
            && $dispatch->herdr_agent_name === strtolower((string) $delivery->external_issue_key).'-loop-pr-review-'.$phase->attempt
            && $dispatch->prompt_name === 'orbit_pr_review'
            && $dispatch->prompt_version === 1
            && hash_equals($dispatch->prompt_hash, hash('sha256', $expectedPrompt))
            && $dispatch->herdr_session === ($config['herdrSession'] ?? null)
            && is_string($dispatch->herdr_workspace_id) && trim($dispatch->herdr_workspace_id) !== ''
            && is_string($dispatch->herdr_tab_id) && trim($dispatch->herdr_tab_id) !== ''
            && is_string($dispatch->herdr_pane_id) && trim($dispatch->herdr_pane_id) !== ''
            && is_string($dispatch->herdr_terminal_id) && trim($dispatch->herdr_terminal_id) !== ''
            && $dispatch->dispatched_at !== null
            && (! $retainedTransition || (
                $phase->status === PhaseRunStatus::Completed
                && $phase->finished_at !== null
                && $dispatch->status === AgentDispatchStatus::Settled
                && $dispatch->settled_at !== null
            ))
            && $this->isIndependentFromBuilders($delivery, $dispatch);
    }

    /** @param array<string, mixed> $payload */
    private function matchesBody(
        Delivery $delivery,
        array $payload,
        string $artifact,
        string $gate,
        mixed $flow,
    ): bool {
        if (($payload['result'] ?? null) !== 'approved') {
            return ($payload['pull_request_body_path'] ?? null) === null
                && ($payload['pull_request_body'] ?? null) === null
                && ($payload['pull_request_body_sha256'] ?? null) === null;
        }

        if (! is_string($flow) || ! in_array($flow, ['discovery', 'proof'], true)) {
            return false;
        }

        $path = $payload['pull_request_body_path'] ?? null;
        $body = $payload['pull_request_body'] ?? null;
        $hash = $payload['pull_request_body_sha256'] ?? null;
        $required = [
            'Issue: '.$delivery->external_issue_key,
            (string) $delivery->candidate_sha,
            $artifact,
            $flow,
            'Builder gate: passed ('.$gate.')',
        ];

        return is_string($path)
            && str_starts_with($path, '.loop/')
            && is_string($body)
            && trim($body) !== ''
            && is_string($hash)
            && hash_equals($hash, hash('sha256', $body))
            && collect($required)->doesntContain(
                static fn (string $binding): bool => ! str_contains($body, $binding),
            );
    }

    private function isIndependentFromBuilders(Delivery $delivery, AgentDispatch $reviewer): bool
    {
        return ! AgentDispatch::query()
            ->whereHas('phaseRun', fn ($query) => $query
                ->where('delivery_id', $delivery->id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE))
            ->get()
            ->contains(fn (AgentDispatch $builder): bool => $builder->herdr_agent_name === $reviewer->herdr_agent_name
                || $builder->herdr_pane_id === $reviewer->herdr_pane_id
                || ($builder->herdr_agent_id !== null
                    && $reviewer->herdr_agent_id !== null
                    && $builder->herdr_agent_id === $reviewer->herdr_agent_id));
    }

    /** @return array<string, mixed>|null */
    private function pullRequestInput(PhaseRun $phase): ?array
    {
        $input = $phase->input;
        $pullRequest = is_array($input) ? ($input['pull_request'] ?? null) : null;

        if (! is_array($pullRequest) || array_is_list($pullRequest)) {
            return null;
        }

        $normalized = [];

        foreach ($pullRequest as $key => $value) {
            if (! is_string($key)) {
                return null;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
