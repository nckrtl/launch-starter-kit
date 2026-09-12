<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\CaptureOrbitPlanningReceipt;
use App\Delivery\Actions\ReconcileOrbitSettledReceiptWait;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitRepository;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Data\PreparedWorktree;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitPlanningReceiptFailed;
use App\Delivery\Exceptions\OrbitRepositoryFailed;
use App\Delivery\Workflow\OrbitFeatureWorkflow;
use App\Models\PhaseRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

#[Signature('delivery:submit-orbit-receipt
    {phase-run : Planning phase run ID from the Commander prompt}
    {dispatch : Agent dispatch ID from the Commander prompt}
    {--result= : Planning result: ready or blocked}
    {--handoff= : Complete handoff file inside the worktree .loop directory}
    {--artifact= : Full saved artifact SHA; required for ready}')]
#[Description('Validate and capture a planning receipt for a live Orbit delivery')]
final class SubmitOrbitPlanningReceiptCommand extends Command
{
    public function handle(
        ProjectConfigRegistry $configs,
        OrbitRepository $repository,
        CaptureOrbitPlanningReceipt $capture,
        ReconcileOrbitSettledReceiptWait $settledReceipts,
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
        $recovering = $phaseRun !== null && $dispatch !== null
            && $settledReceipts->canRecoverLateReceipt(
                $phaseRun->delivery,
                $phaseRun->delivery->projectOrchestration,
                $phaseRun,
                $dispatch,
                $phaseRun->agentDispatches->count(),
                $phaseRun->receipts()->count(),
            );

        if ($phaseRun === null || $dispatch === null || $phaseRun->agentDispatches->count() !== 1
            || $phaseRun->delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $phaseRun->delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phaseRun->delivery->current_phase !== OrbitFeatureWorkflow::INITIAL_PHASE
            || $phaseRun->phase_name !== OrbitFeatureWorkflow::INITIAL_PHASE
            || (! $recovering && $phaseRun->status !== PhaseRunStatus::Running)
            || (! ($dispatch->status === AgentDispatchStatus::Starting
                && $dispatch->error_code === 'herdr_prompt_attempted')
                && ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))) {
            $this->error('The planning phase run and dispatch do not match an active Orbit worker.');

            return self::FAILURE;
        }

        $result = $this->option('result');
        $handoffOption = $this->option('handoff');
        $artifact = $this->option('artifact');

        if (! is_string($result) || ! in_array($result, ['ready', 'blocked'], true)) {
            $this->error('The planning result must be ready or blocked.');

            return self::FAILURE;
        }

        if (($result === 'ready' && (! is_string($artifact) || preg_match('/^[a-f0-9]{40}$/', $artifact) !== 1))
            || ($result === 'blocked' && $artifact !== null)) {
            $this->error('Ready requires one full artifact SHA; blocked must not include an artifact.');

            return self::FAILURE;
        }

        $worktree = realpath((string) $phaseRun->delivery->worktree_path);
        $workingDirectory = getcwd();

        if ($worktree === false || $worktree !== $phaseRun->delivery->worktree_path
            || $workingDirectory === false || realpath($workingDirectory) !== $worktree) {
            $this->error('Run this command from the exact delivery worktree.');

            return self::FAILURE;
        }

        $handoff = $this->readHandoff($worktree, $handoffOption);

        if ($handoff === null) {
            return self::FAILURE;
        }

        try {
            $head = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        } catch (RuntimeException) {
            $this->error('The worktree HEAD could not be inspected.');

            return self::FAILURE;
        }

        $headSha = trim($head->output());

        if ($head->failed() || preg_match('/^[a-f0-9]{40}$/', $headSha) !== 1) {
            $this->error('The worktree HEAD is not a valid planning candidate.');

            return self::FAILURE;
        }

        try {
            $config = $configs->hydrate($phaseRun->delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig) {
                throw new InvalidArgumentException('The delivery does not use Orbit project configuration.');
            }

            $verifiedArtifact = $result === 'ready'
                ? $repository->verifyPlanningArtifact(
                    $config,
                    new PreparedWorktree($worktree, $headSha),
                    (string) $phaseRun->delivery->external_issue_key,
                    (string) $artifact,
                    'PENDING',
                )
                : null;

            $receipt = $capture->handle($phaseRun, $dispatch, [
                'kind' => 'orbit_planning',
                'schema_version' => 1,
                'delivery_id' => $phaseRun->delivery_id,
                'dispatch_id' => $dispatch->id,
                'issue_key' => $phaseRun->delivery->external_issue_key,
                'phase' => $phaseRun->phase_name,
                'attempt' => $phaseRun->attempt,
                'result' => $result,
                'worktree' => $worktree,
                'candidate_sha' => $headSha,
                'handoff_path' => substr($handoff['path'], strlen($worktree) + 1),
                'handoff' => $handoff['contents'],
                'artifact_sha' => $verifiedArtifact?->artifactSha,
                'plan_sha256' => $verifiedArtifact?->planContentsHash,
            ]);
        } catch (InvalidArgumentException|ValidationException|OrbitRepositoryFailed|OrbitPlanningReceiptFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($receipt->wasRecentlyCreated) {
            $this->info("Orbit planning receipt {$receipt->id} captured for phase run {$phaseRun->id}.");
        } else {
            $this->info("Orbit planning receipt {$receipt->id} was already captured.");
        }

        return self::SUCCESS;
    }

    /** @return array{path: string, contents: string}|null */
    private function readHandoff(string $worktree, mixed $option): ?array
    {
        if (! is_string($option) || trim($option) === '') {
            $this->error('A complete handoff file is required.');

            return null;
        }

        $loop = $worktree.'/.loop';
        $path = str_starts_with($option, '/') ? $option : $worktree.'/'.$option;
        $resolvedLoop = realpath($loop);
        $resolved = realpath($path);

        if ($resolvedLoop === false || is_link($loop) || ! is_dir($resolvedLoop)
            || $resolved === false || is_link($path) || ! is_file($resolved)
            || ! str_starts_with($resolved, $resolvedLoop.'/')) {
            $this->error("Handoff must be a regular file inside the issue's .loop directory.");

            return null;
        }

        $contents = file_get_contents($resolved);
        $contents = $contents === false ? '' : trim($contents);

        if ($contents === '') {
            $this->error('Handoff is empty.');

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
