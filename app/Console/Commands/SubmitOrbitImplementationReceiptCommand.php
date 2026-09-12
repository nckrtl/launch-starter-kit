<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\CaptureOrbitImplementationReceipt;
use App\Delivery\Actions\ReconcileOrbitSettledReceiptWait;
use App\Delivery\Actions\ResolveOrbitDeliveryPreparation;
use App\Delivery\Config\ProjectConfigRegistry;
use App\Delivery\Contracts\OrbitImplementationRepository;
use App\Delivery\Data\OrbitProjectConfig;
use App\Delivery\Enums\AgentDispatchStatus;
use App\Delivery\Enums\DeliveryStatus;
use App\Delivery\Enums\PhaseRunStatus;
use App\Delivery\Exceptions\OrbitImplementationReceiptFailed;
use App\Delivery\Exceptions\OrbitPlanningHandoffFailed;
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

#[Signature('delivery:submit-orbit-implementation-receipt
    {phase-run : Implementation phase run ID from the Commander prompt}
    {dispatch : Agent dispatch ID from the Commander prompt}
    {--result= : Implementation result: ready or blocked}
    {--handoff= : Complete handoff file inside the worktree .loop directory}
    {--artifact= : Full published artifact SHA; required for ready}
    {--gate= : Absolute Builder gate receipt path; required for ready}
    {--body= : Complete pull request body file inside .loop; required for ready}')]
#[Description('Validate and capture an implementation receipt for a live Orbit delivery')]
final class SubmitOrbitImplementationReceiptCommand extends Command
{
    public function handle(
        ProjectConfigRegistry $configs,
        ResolveOrbitDeliveryPreparation $preparations,
        OrbitImplementationRepository $repository,
        CaptureOrbitImplementationReceipt $capture,
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
        $latestImplementationId = $phaseRun === null
            ? null
            : PhaseRun::query()
                ->where('delivery_id', $phaseRun->delivery_id)
                ->where('phase_name', OrbitFeatureWorkflow::IMPLEMENTATION_PHASE)
                ->latest('attempt')
                ->value('id');

        if ($phaseRun === null || $dispatch === null || $phaseRun->agentDispatches->count() !== 1
            || $latestImplementationId !== $phaseRun->id
            || $phaseRun->delivery->workflow_type !== OrbitFeatureWorkflow::TYPE
            || $phaseRun->delivery->workflow_version !== OrbitFeatureWorkflow::VERSION
            || $phaseRun->delivery->current_phase !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || (! $recovering && $phaseRun->delivery->status !== DeliveryStatus::WaitingForAgent
                && ! ($phaseRun->delivery->status === DeliveryStatus::Preparing
                    && $dispatch->status === AgentDispatchStatus::Starting
                    && $dispatch->error_code === 'herdr_prompt_attempted'))
            || $phaseRun->phase_name !== OrbitFeatureWorkflow::IMPLEMENTATION_PHASE
            || $phaseRun->attempt < 1
            || (! $recovering && $phaseRun->status !== PhaseRunStatus::Running)
            || $dispatch->agent_role !== OrbitFeatureWorkflow::IMPLEMENTATION_AGENT_ROLE
            || (! ($dispatch->status === AgentDispatchStatus::Starting
                && $dispatch->error_code === 'herdr_prompt_attempted')
                && ! in_array($dispatch->status, [AgentDispatchStatus::Waiting, AgentDispatchStatus::Settled], true))) {
            $this->error('The implementation phase run and dispatch do not match the active Orbit Builder.');

            return self::FAILURE;
        }

        $result = $this->option('result');
        $artifact = $this->option('artifact');
        $gate = $this->option('gate');
        $bodyOption = $this->option('body');

        if (! is_string($result) || ! in_array($result, ['ready', 'blocked'], true)) {
            $this->error('The implementation result must be ready or blocked.');

            return self::FAILURE;
        }

        if (($result === 'ready' && (
            ! is_string($artifact) || preg_match('/^[a-f0-9]{40}$/', $artifact) !== 1
            || ! is_string($gate) || trim($gate) === ''
            || ! is_string($bodyOption) || trim($bodyOption) === ''
        )) || ($result === 'blocked' && ($artifact !== null || $gate !== null || $bodyOption !== null))) {
            $this->error('Ready requires one artifact, gate, and body; blocked must not include implementation evidence.');

            return self::FAILURE;
        }

        $worktree = realpath((string) $phaseRun->delivery->worktree_path);
        $workingDirectory = getcwd();

        if ($worktree === false || $worktree !== $phaseRun->delivery->worktree_path
            || $workingDirectory === false || realpath($workingDirectory) !== $worktree) {
            $this->error('Run this command from the exact delivery worktree.');

            return self::FAILURE;
        }

        $handoff = $this->readLoopFile($worktree, $this->option('handoff'), 'Handoff');

        if ($handoff === null) {
            return self::FAILURE;
        }

        $body = $result === 'ready'
            ? $this->readLoopFile($worktree, $bodyOption, 'Pull request body')
            : null;

        if ($result === 'ready' && $body === null) {
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
            $this->error('The worktree HEAD is not a valid implementation candidate.');

            return self::FAILURE;
        }

        try {
            $config = $configs->hydrate($phaseRun->delivery->projectOrchestration->config);

            if (! $config instanceof OrbitProjectConfig) {
                throw new InvalidArgumentException('The delivery does not use Orbit project configuration.');
            }

            $preparation = $preparations->startup($phaseRun->delivery);
            $verified = $result === 'ready'
                ? $repository->verifyImplementationOutcome(
                    $config,
                    $preparation->worktree,
                    $preparation->snapshot,
                    (string) $phaseRun->delivery->candidate_sha,
                    $headSha,
                    (string) $artifact,
                    (string) $gate,
                    (string) $body['contents'],
                )
                : null;
            $receipt = $capture->handle($phaseRun, $dispatch, [
                'kind' => 'orbit_implementation',
                'schema_version' => 1,
                'delivery_id' => $phaseRun->delivery_id,
                'dispatch_id' => $dispatch->id,
                'issue_key' => $phaseRun->delivery->external_issue_key,
                'phase' => $phaseRun->phase_name,
                'attempt' => $phaseRun->attempt,
                'result' => $result,
                'worktree' => $worktree,
                'reviewed_candidate_sha' => $phaseRun->delivery->candidate_sha,
                'candidate_sha' => $headSha,
                'handoff_path' => substr($handoff['path'], strlen($worktree) + 1),
                'handoff' => $handoff['contents'],
                'artifact_sha' => $verified?->artifactSha,
                'gate_receipt_path' => $verified?->gateReceiptPath,
                'pull_request_body_path' => $body === null
                    ? null
                    : substr($body['path'], strlen($worktree) + 1),
                'pull_request_body' => $body['contents'] ?? null,
                'pull_request_body_sha256' => $verified?->pullRequestBodyHash,
                'flow' => $verified?->flow,
            ]);
        } catch (InvalidArgumentException|ValidationException|OrbitPlanningHandoffFailed|OrbitRepositoryFailed|OrbitImplementationReceiptFailed $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($receipt->wasRecentlyCreated) {
            $this->info("Orbit implementation receipt {$receipt->id} captured for phase run {$phaseRun->id}.");
        } else {
            $this->info("Orbit implementation receipt {$receipt->id} was already captured.");
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
