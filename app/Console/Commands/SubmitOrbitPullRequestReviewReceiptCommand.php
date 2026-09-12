<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\CaptureOrbitPullRequestReviewReceipt;
use App\Delivery\Actions\ResolveOrbitDeliveryPreparation;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Contracts\OrbitPullRequestInspector;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
use App\Delivery\Exceptions\OrbitPullRequestPublicationFailed;
use App\Delivery\Exceptions\OrbitPullRequestReviewReceiptFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitPullRequestReviewReceiptValidator;
use App\Delivery\Workflow\OrbitPullRequestReviewSourceValidator;
use App\Models\PhaseRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

#[Signature('delivery:submit-orbit-pr-review-receipt
    {phase-run : Pull request review phase run ID from the Commander prompt}
    {dispatch : Agent dispatch ID from the Commander prompt}
    {--result= : Review result: approved, changes, or blocked}
    {--handoff= : Complete review handoff file inside the worktree .loop directory}
    {--artifact= : Exact submitted implementation artifact SHA}
    {--body= : Final pull request body file inside .loop; required only for approved}')]
#[Description('Validate and capture an independent Orbit pull request review receipt')]
final class SubmitOrbitPullRequestReviewReceiptCommand extends Command
{
    public function handle(
        ProjectConfigRegistry $configs,
        ResolveOrbitDeliveryPreparation $preparations,
        OrbitImplementationRepository $repository,
        OrbitPullRequestInspector $pullRequests,
        OrbitPullRequestReviewSourceValidator $sources,
        OrbitPullRequestReviewReceiptValidator $reviewReceipts,
        CaptureOrbitPullRequestReviewReceipt $capture,
    ): int {
        $phaseRunId = $this->positiveIntegerArgument('phase-run');
        $dispatchId = $this->positiveIntegerArgument('dispatch');

        if ($phaseRunId === null || $dispatchId === null) {
            $this->error('The phase run and dispatch IDs must be positive integers.');

            return self::FAILURE;
        }

        $phaseRun = PhaseRun::query()
            ->with(['delivery.projectOrchestration', 'agentDispatches', 'receipts'])
            ->find($phaseRunId);
        $dispatch = $phaseRun?->agentDispatches->firstWhere('id', $dispatchId);
        $latestReviewId = $phaseRun === null
            ? null
            : PhaseRun::query()
                ->where('delivery_id', $phaseRun->delivery_id)
                ->where('phase_name', OrbitFeatureWorkflow::PR_REVIEW_PHASE)
                ->latest('attempt')
                ->value('id');

        $active = $phaseRun !== null && $dispatch !== null
            && ($phaseRun->delivery->status === DeliveryStatus::WaitingForAgent
                || ($phaseRun->delivery->status === DeliveryStatus::Preparing
                    && $dispatch->status === AgentDispatchStatus::Starting
                    && $dispatch->error_code === 'herdr_prompt_attempted'))
            && $phaseRun->status === PhaseRunStatus::Running
            && (($dispatch->status === AgentDispatchStatus::Starting
                && $dispatch->error_code === 'herdr_prompt_attempted')
                || in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true));
        $recovering = $phaseRun !== null && $dispatch !== null
            && $capture->canRecoverLateReceipt(
                $phaseRun->delivery,
                $phaseRun,
                $dispatch,
                $phaseRun->agentDispatches->count(),
                $phaseRun->receipts->contains('kind', 'orbit_pr_review'),
            );

        if ($phaseRun === null || $dispatch === null || $phaseRun->agentDispatches->count() !== 1
            || $latestReviewId !== $phaseRun->id
            || $phaseRun->delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $phaseRun->delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phaseRun->delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $phaseRun->delivery->current_phase !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || $phaseRun->phase_name !== OrbitFeatureWorkflow::PR_REVIEW_PHASE
            || ! in_array($phaseRun->attempt, [1, 2], true)
            || $dispatch->agent_role !== OrbitFeatureWorkflow::PR_REVIEW_AGENT_ROLE
            || (! $active && ! $recovering)) {
            $this->error('The pull request review phase run and dispatch do not match an active reviewer.');

            return self::FAILURE;
        }

        $result = $this->option('result');
        $artifact = $this->option('artifact');
        $bodyOption = $this->option('body');

        if (! is_string($result) || ! in_array($result, ['approved', 'changes', 'blocked'], true)) {
            $this->error('The pull request review result must be approved, changes, or blocked.');

            return self::FAILURE;
        }

        if (! is_string($artifact) || preg_match('/^[a-f0-9]{40}$/', $artifact) !== 1) {
            $this->error('Every pull request review result requires the exact submitted artifact SHA.');

            return self::FAILURE;
        }

        if (($result === 'approved' && (! is_string($bodyOption) || trim($bodyOption) === ''))
            || ($result !== 'approved' && $bodyOption !== null)) {
            $this->error('Approved requires one final body; changes and blocked must not include a body.');

            return self::FAILURE;
        }

        $worktree = realpath((string) $phaseRun->delivery->worktree_path);
        $workingDirectory = getcwd();

        if ($worktree === false || $worktree !== $phaseRun->delivery->worktree_path
            || $workingDirectory === false || realpath($workingDirectory) !== $worktree) {
            $this->error('Run this command from the exact delivery worktree.');

            return self::FAILURE;
        }

        $handoff = $this->readLoopFile($worktree, $this->option('handoff'), 'Review handoff');

        if ($handoff === null) {
            return self::FAILURE;
        }

        $body = $result === 'approved'
            ? $this->readLoopFile($worktree, $bodyOption, 'Pull request body')
            : null;

        if ($result === 'approved' && $body === null) {
            return self::FAILURE;
        }

        try {
            $head = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (RuntimeException) {
            $this->error('The reviewed worktree HEAD could not be inspected.');

            return self::FAILURE;
        }

        $headSha = trim($head->output());

        if ($head->failed() || $headSha !== $phaseRun->delivery->candidate_sha) {
            $this->error('The reviewer changed the candidate or reviewed another head.');

            return self::FAILURE;
        }

        try {
            $config = $configs->hydrate($phaseRun->delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig) {
                throw new InvalidArgumentException('The delivery does not use Orbit project configuration.');
            }

            $source = $sources->sourceReceipt($phaseRun->delivery, $phaseRun);

            if ($source === null) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The implementation receipt no longer matches the active pull request review.',
                );
            }

            if (! $reviewReceipts->matchesInput($phaseRun->delivery, $phaseRun, $dispatch)) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The pull request review dispatch no longer matches its immutable prompt.',
                );
            }

            $sourcePayload = $source->payload;
            $reviewedCandidate = $this->string($sourcePayload, 'reviewed_candidate_sha');
            $candidate = $this->string($sourcePayload, 'candidate_sha');
            $sourceArtifact = $this->string($sourcePayload, 'artifact_sha');
            $gate = $this->string($sourcePayload, 'gate_receipt_path');
            $submittedBody = $this->string($sourcePayload, 'pull_request_body');
            $pullRequestNumber = $phaseRun->delivery->pull_request_number;

            if ($candidate !== $headSha || $sourceArtifact !== $artifact
                || ! is_int($pullRequestNumber) || $pullRequestNumber < 1) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The review evidence does not match the submitted implementation.',
                );
            }

            $preparation = $preparations->startup($phaseRun->delivery);
            $reviewBody = $body['contents'] ?? $submittedBody;
            $verified = $repository->verifyImplementationOutcome(
                $config,
                $preparation->worktree,
                $preparation->snapshot,
                $reviewedCandidate,
                $candidate,
                $sourceArtifact,
                $gate,
                $reviewBody,
            );
            $published = $pullRequests->inspect(
                $pullRequestNumber,
                (string) $phaseRun->delivery->external_issue_key,
                $candidate,
                $submittedBody,
            );

            if ($verified->candidateSha !== $candidate
                || $verified->artifactSha !== $sourceArtifact
                || $verified->gateReceiptPath !== $gate
                || $verified->pullRequestBodyHash !== hash('sha256', $reviewBody)
                || $verified->flow !== 'discovery'
                || $published->number !== $pullRequestNumber
                || $published->url !== $phaseRun->delivery->pull_request_url
                || $published->candidateSha !== $candidate
                || $published->bodyHash !== hash('sha256', $submittedBody)
                || $published->mergeable !== true) {
                throw new OrbitPullRequestReviewReceiptFailed(
                    'The verified review evidence no longer matches the published pull request.',
                );
            }

            $receipt = $capture->handle($phaseRun, $dispatch, $config, [
                'kind' => 'orbit_pr_review',
                'schema_version' => 1,
                'delivery_id' => $phaseRun->delivery_id,
                'dispatch_id' => $dispatch->id,
                'issue_key' => $phaseRun->delivery->external_issue_key,
                'phase' => $phaseRun->phase_name,
                'attempt' => $phaseRun->attempt,
                'result' => $result,
                'worktree' => $worktree,
                'candidate_sha' => $candidate,
                'handoff_path' => substr($handoff['path'], strlen($worktree) + 1),
                'handoff' => $handoff['contents'],
                'artifact_sha' => $sourceArtifact,
                'pull_request_body_path' => $body === null
                    ? null
                    : substr($body['path'], strlen($worktree) + 1),
                'pull_request_body' => $body['contents'] ?? null,
                'pull_request_body_sha256' => $body === null ? null : $verified->pullRequestBodyHash,
            ]);
        } catch (InvalidArgumentException|ValidationException|OrbitPlanningHandoffFailed|OrbitRepositoryFailed|OrbitPullRequestPublicationFailed|OrbitPullRequestReviewReceiptFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($receipt->wasRecentlyCreated) {
            $this->info("Orbit pull request review receipt {$receipt->id} captured for phase run {$phaseRun->id}.");
        } else {
            $this->info("Orbit pull request review receipt {$receipt->id} was already captured.");
        }

        return self::SUCCESS;
    }

    /** @return array{path: string, contents: string}|null */
    private function readLoopFile(string $worktree, mixed $option, string $label): ?array
    {
        if (! is_string($option) || trim($option) === '') {
            $this->error("A complete {$label} file is required.");

            return null;
        }

        $loop = $worktree.'/.loop';
        $path = str_starts_with($option, '/') ? $option : $worktree.'/'.$option;
        $resolvedLoop = realpath($loop);
        $resolved = realpath($path);

        if ($resolvedLoop === false || is_link($loop) || ! is_dir($resolvedLoop)
            || $resolved === false || is_link($path) || ! is_file($resolved)
            || ! str_starts_with($resolved, $resolvedLoop.'/')) {
            $this->error("{$label} must be a regular file inside the issue's .loop directory.");

            return null;
        }

        $contents = file_get_contents($resolved);
        $contents = $contents === false ? '' : trim($contents);

        if ($contents === '') {
            $this->error("{$label} is empty.");

            return null;
        }

        return ['path' => $resolved, 'contents' => $contents];
    }

    /** @param array<string, mixed> $payload */
    private function string(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new OrbitPullRequestReviewReceiptFailed(
                "The implementation receipt has invalid {$key} evidence.",
            );
        }

        return $value;
    }

    private function positiveIntegerArgument(string $name): ?int
    {
        $value = $this->argument($name);

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
