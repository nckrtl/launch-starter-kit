<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\CaptureOrbitPullRequestResolutionReceipt;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Enums\ProjectOrchestrationState;
use App\Delivery\Exceptions\OrbitResolutionReceiptFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Delivery\Workflow\OrbitResolutionReceiptValidator;
use App\Models\PhaseRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;

#[Signature('delivery:submit-orbit-resolution-receipt
    {phase-run : Resolution phase run ID from the Commander prompt}
    {dispatch : Resolver dispatch ID from the Commander prompt}
    {--result= : Resolution result: proposal or blocked}
    {--handoff= : Complete resolution handoff file inside the worktree .loop directory}
    {--resolution= : Structured resolution JSON inside .loop; required for proposal}')]
#[Description('Validate and capture an advisory Orbit resolution receipt')]
final class SubmitOrbitResolutionReceiptCommand extends Command
{
    public function handle(
        ProjectConfigRegistry $configs,
        OrbitResolutionReceiptValidator $receipts,
        CaptureOrbitPullRequestResolutionReceipt $capture,
    ): int {
        $phaseRunId = $this->positiveIntegerArgument('phase-run');
        $dispatchId = $this->positiveIntegerArgument('dispatch');

        if ($phaseRunId === null || $dispatchId === null) {
            $this->error('The phase run and dispatch IDs must be positive integers.');

            return self::FAILURE;
        }

        $phaseRun = PhaseRun::query()
            ->with(['delivery.projectOrchestration', 'agentDispatches'])
            ->find($phaseRunId);
        $dispatch = $phaseRun?->agentDispatches->firstWhere('id', $dispatchId);
        $latestResolutionId = $phaseRun === null
            ? null
            : PhaseRun::query()
                ->where('delivery_id', $phaseRun->delivery_id)
                ->where('phase_name', OrbitFeatureWorkflow::RESOLUTION_PHASE)
                ->latest('attempt')
                ->value('id');

        if ($phaseRun === null || $dispatch === null || $phaseRun->agentDispatches->count() !== 1
            || $latestResolutionId !== $phaseRun->id
            || $phaseRun->delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $phaseRun->delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phaseRun->delivery->projectOrchestration->state !== ProjectOrchestrationState::Enabled
            || $phaseRun->delivery->current_phase !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || ($phaseRun->delivery->status !== DeliveryStatus::WaitingForAgent
                && ! ($phaseRun->delivery->status === DeliveryStatus::Preparing
                    && $dispatch->status === AgentDispatchStatus::Starting
                    && $dispatch->error_code === 'herdr_prompt_attempted'))
            || $phaseRun->phase_name !== OrbitFeatureWorkflow::RESOLUTION_PHASE
            || $phaseRun->attempt < 1
            || $phaseRun->status !== PhaseRunStatus::Running
            || $dispatch->agent_role !== OrbitFeatureWorkflow::RESOLUTION_AGENT_ROLE
            || (! ($dispatch->status === AgentDispatchStatus::Starting
                && $dispatch->error_code === 'herdr_prompt_attempted')
                && ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))) {
            $this->error('The resolution phase run and dispatch do not match the active Orbit resolver.');

            return self::FAILURE;
        }

        $result = $this->option('result');
        $resolutionOption = $this->option('resolution');

        if (! is_string($result) || ! in_array($result, ['proposal', 'blocked'], true)) {
            $this->error('The resolution result must be proposal or blocked.');

            return self::FAILURE;
        }

        if (($result === 'proposal' && (! is_string($resolutionOption) || trim($resolutionOption) === ''))
            || ($result === 'blocked' && $resolutionOption !== null)) {
            $this->error('Proposal requires one resolution JSON file; blocked must not include one.');

            return self::FAILURE;
        }

        $worktree = realpath((string) $phaseRun->delivery->worktree_path);
        $workingDirectory = getcwd();

        if ($worktree === false || $worktree !== $phaseRun->delivery->worktree_path
            || $workingDirectory === false || realpath($workingDirectory) !== $worktree) {
            $this->error('Run this command from the exact delivery worktree.');

            return self::FAILURE;
        }

        $handoff = $this->readLoopFile($worktree, $this->option('handoff'), 'Resolution handoff');

        if ($handoff === null) {
            return self::FAILURE;
        }

        $resolutionFile = $result === 'proposal'
            ? $this->readLoopFile($worktree, $resolutionOption, 'Resolution JSON')
            : null;

        if ($result === 'proposal' && $resolutionFile === null) {
            return self::FAILURE;
        }

        try {
            $head = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (RuntimeException) {
            $this->error('The resolution worktree HEAD could not be inspected.');

            return self::FAILURE;
        }

        $candidateSha = trim($head->output());

        if ($head->failed() || $candidateSha !== $phaseRun->delivery->candidate_sha) {
            $this->error('The resolver changed the candidate or inspected another head.');

            return self::FAILURE;
        }

        try {
            $proposal = $resolutionFile === null
                ? null
                : json_decode($resolutionFile['contents'], true, flags: JSON_THROW_ON_ERROR);
            $config = $configs->hydrate($phaseRun->delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig) {
                throw new OrbitResolutionReceiptFailed(
                    'The delivery does not use Orbit project configuration.',
                );
            }

            if (! $receipts->matchesInput($phaseRun->delivery, $phaseRun, $dispatch)) {
                throw new OrbitResolutionReceiptFailed(
                    'The resolver dispatch no longer matches its immutable input.',
                );
            }

            $receipt = $capture->handle($phaseRun, $dispatch, $config, [
                'kind' => 'orbit_resolution',
                'schema_version' => 1,
                'delivery_id' => $phaseRun->delivery_id,
                'dispatch_id' => $dispatch->id,
                'issue_key' => $phaseRun->delivery->external_issue_key,
                'phase' => $phaseRun->phase_name,
                'attempt' => $phaseRun->attempt,
                'result' => $result,
                'worktree' => $worktree,
                'candidate_sha' => $candidateSha,
                'handoff_path' => substr($handoff['path'], strlen($worktree) + 1),
                'handoff' => $handoff['contents'],
                'handoff_sha256' => hash('sha256', $handoff['contents']),
                'resolution_path' => $resolutionFile === null
                    ? null
                    : substr($resolutionFile['path'], strlen($worktree) + 1),
                'resolution' => $proposal,
                'resolution_sha256' => $proposal === null
                    ? null
                    : hash('sha256', json_encode($proposal, JSON_THROW_ON_ERROR)),
            ]);
        } catch (JsonException|OrbitResolutionReceiptFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($receipt->wasRecentlyCreated) {
            $this->info("Orbit resolution receipt {$receipt->id} captured for phase run {$phaseRun->id}.");
        } else {
            $this->info("Orbit resolution receipt {$receipt->id} was already captured.");
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

    private function positiveIntegerArgument(string $name): ?int
    {
        $value = $this->argument($name);

        if (! is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1) {
            return null;
        }

        return (int) $value;
    }
}
