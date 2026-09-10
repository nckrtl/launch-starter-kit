<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Delivery\Actions\CaptureHarmlessReceipt;
use App\Delivery\Enums\ReceiptValidationStatus;
use App\Delivery\Workflow\ShadowWorkflow;
use App\Models\PhaseRun;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;

#[Signature('delivery:submit-shadow-receipt
    {phase-run : Phase run ID from the Commander prompt}
    {dispatch : Agent dispatch ID from the Commander prompt}')]
#[Description('Submit the harmless receipt for a Commander shadow delivery')]
final class SubmitShadowReceiptCommand extends Command
{
    public function handle(CaptureHarmlessReceipt $capture): int
    {
        $phaseRunId = $this->positiveIntegerArgument('phase-run');
        $dispatchId = $this->positiveIntegerArgument('dispatch');

        if ($phaseRunId === null || $dispatchId === null) {
            $this->error('The phase run and dispatch IDs must be positive integers.');

            return self::FAILURE;
        }

        $phaseRun = PhaseRun::query()->with(['delivery', 'agentDispatches', 'receipts'])->find($phaseRunId);
        $dispatch = $phaseRun?->agentDispatches->firstWhere('id', $dispatchId);

        if ($phaseRun === null || $phaseRun->delivery->workflow_type !== ShadowWorkflow::TYPE || $dispatch === null) {
            $this->error('The shadow phase run and dispatch do not match.');

            return self::FAILURE;
        }

        $existing = $phaseRun->receipts->firstWhere('kind', 'herdr_test');

        if ($existing !== null) {
            $this->info("Shadow receipt {$existing->id} was already captured.");

            return $existing->validation_status === ReceiptValidationStatus::Valid ? self::SUCCESS : self::FAILURE;
        }

        $worktree = realpath((string) $phaseRun->delivery->worktree_path);
        $workingDirectory = getcwd();

        if ($worktree === false || $workingDirectory === false || realpath($workingDirectory) !== $worktree) {
            $this->error('Run this command from the delivery worktree.');

            return self::FAILURE;
        }

        $result = Process::path($worktree)->timeout(10)->run(['git', 'rev-parse', 'HEAD']);
        $headSha = trim($result->output());

        if ($result->failed() || $headSha !== $phaseRun->delivery->candidate_sha) {
            $this->error('The worktree HEAD does not match the delivery candidate.');

            return self::FAILURE;
        }

        $receipt = $capture->handle($phaseRun, [
            'kind' => 'herdr_test',
            'schema_version' => 1,
            'delivery_id' => $phaseRun->delivery_id,
            'dispatch_id' => $dispatch->id,
            'issue_key' => $phaseRun->delivery->external_issue_key,
            'phase' => $phaseRun->phase_name,
            'attempt' => $phaseRun->attempt,
            'outcome' => 'success',
            'worktree' => $worktree,
            'head_sha' => $headSha,
            'commands' => [['command' => 'git rev-parse HEAD', 'exit_code' => 0]],
            'artifacts' => [],
            'summary' => 'Harmless Herdr shadow check completed.',
            'created_at' => now()->toISOString(),
        ]);

        if ($receipt->validation_status !== ReceiptValidationStatus::Valid) {
            $this->error('Commander rejected the shadow receipt.');

            return self::FAILURE;
        }

        $this->info("Shadow receipt {$receipt->id} captured for phase run {$phaseRun->id}.");

        return self::SUCCESS;
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
