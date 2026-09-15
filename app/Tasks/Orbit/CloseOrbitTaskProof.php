<?php

declare(strict_types=1);

namespace App\Tasks\Orbit;

use App\Delivery\Contracts\OrbitPrimaryCheckoutReconciler;
use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Closeout\TaskCloseoutContext;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\GitObjectId;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Runtime\TaskProcessEnvironment;
use Illuminate\Support\Facades\Process;
use LogicException;

final readonly class CloseOrbitTaskProof
{
    public function __construct(private NativeTaskProofCloseout $native, private TaskCloseoutLedger $ledger,
        private TaskCloseoutContext $context, private OrbitPrimaryCheckoutReconciler $primary) {}

    /** Caller owns the existing Tasks closeout lock and has verified the merge.
     * @param  array<string,mixed>  $merge
     * @return array<string,mixed>
     */
    public function handle(TaskLanding $landing, array $merge, string $stage, bool $apply): array
    {
        $workspace = $landing->workspace()->firstOrFail();
        if ((OrbitTaskProfile::forWorkspace($workspace)['flow'] ?? null) !== 'proof'
            || ($landing->package['schema'] ?? null) !== 2) {
            throw new LogicException('Native proof stages require an explicitly admitted proof landing.');
        }
        $verification = $this->verification($landing, $merge);
        $main = TaskLandingData::text($verification, 'main_sha');
        GitObjectId::validate($main);
        $input = ['package_hash' => $landing->package_hash, 'candidate_sha' => $landing->candidate_sha,
            'artifact_sha' => $landing->artifact_sha, 'merge_sha' => $merge['merge_sha'], 'main_sha' => $main];
        $primaryName = 'reconcile-primary:'.$main;
        if ($stage === 'reconcile-primary') {
            $observed = $this->inspectPrimary($landing, $main);
            if (! $apply) {
                return ['applied' => false, 'stage' => $stage, 'main_sha' => $main, 'primary' => $observed];
            }
            $record = $this->ledger->step($landing, $primaryName, $input,
                fn (): ?array => $this->inspectPrimary($landing, $main),
                function () use ($workspace, $merge, $main): void {
                    $result = $this->primary->reconcilePrimaryCheckout($this->context->configuration($workspace), TaskLandingData::text($merge, 'merge_sha'));
                    if ($result->mainSha !== $main) {
                        throw new LogicException('Primary advanced beyond the verified main; inspect before further closeout.');
                    }
                });

            return ['applied' => true, 'stage' => $stage, 'primary' => $record];
        }
        if ($stage !== 'proof-closeout') {
            throw new LogicException('Unsupported native proof closeout stage.');
        }
        $primary = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $primaryName)->first();
        if ($primary === null || $primary->result === null || $primary->result !== $this->inspectPrimary($landing, $main)) {
            throw new LogicException('Reconcile clean primary at the exact verified main before native proof closeout.');
        }
        $this->assertOperation($landing, $primary);
        $observation = $this->native->inspect($landing, $merge);
        $native = $observation['closeout'];
        if ($native !== null && (TaskLandingData::object($native)['main_sha'] ?? null) !== $main) {
            throw new LogicException('Native closeout belongs to another verified main; reconcile its original intent.');
        }
        if (! $apply) {
            return ['applied' => false, 'stage' => $stage, 'main_sha' => $main, 'native' => $observation,
                'reacquisition' => 'not_performed'];
        }
        $prior = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'proof-closeout:%')->orderByDesc('id')->first();
        if ($prior !== null && $prior->result === null) {
            $this->assertOperation($landing, $prior);
            if (($prior->input['main_sha'] ?? null) !== $main) {
                throw new LogicException('An unresolved native closeout belongs to another verified main; inspect its original intent.');
            }
            $name = $prior->operation;
            $input = $prior->input;
        } elseif ($prior !== null && ($prior->result['state'] ?? null) === 'complete') {
            $this->assertOperation($landing, $prior);
            if ($prior->result !== $native || ($observation['proof_released'] ?? null) !== true) {
                throw new LogicException('Completed native closeout differs from current evidence; do not replay installation.');
            }

            return ['applied' => false, 'stage' => $stage, 'closeout' => $prior->result, 'reacquisition' => 'not_performed'];
        } else {
            if ($prior !== null) {
                $this->assertOperation($landing, $prior);
                if ($prior->result !== $native) {
                    throw new LogicException('Native state changed after the last known attempt; reconcile before another write.');
                }
            }
            $number = $prior === null ? 1 : ((int) substr($prior->operation, strlen('proof-closeout:'))) + 1;
            $name = 'proof-closeout:'.$number;
            $input['before'] = $native;
        }
        $record = $this->ledger->step($landing, $name, $input,
            function () use ($landing, $merge, $input): ?array {
                $observed = $this->native->inspect($landing, $merge)['closeout'];
                $current = $observed === null ? null : TaskLandingData::object($observed);

                return $current === null || ($current === ($input['before'] ?? null) && ($current['state'] ?? null) !== 'complete')
                    ? null : TaskLandingData::object($current);
            },
            function () use ($landing, $merge, $main): void {
                $this->native->execute($landing, $merge, $main);
            },
            function () use ($landing, $merge, $input, $main): array {
                if ($this->inspectPrimary($landing, $main) === null
                    || $this->native->inspect($landing, $merge)['closeout'] !== ($input['before'] ?? null)) {
                    throw new LogicException('The exact native closeout preflight changed before intent.');
                }

                return ['main_sha' => $main, 'native_before' => $input['before'] ?? null];
            });

        return ['applied' => true, 'stage' => $stage, 'closeout' => $record, 'reacquisition' => 'not_performed'];
    }

    /** @param array<string,mixed> $merge
     * @return array<string,mixed>
     */
    private function verification(TaskLanding $landing, array $merge): array
    {
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)
            ->where('operation', 'like', 'verify:%')->where('state', 'completed')->orderByDesc('id')->first();
        if ($operation === null) {
            throw new LogicException('Verify the exact proof merge before postmerge stages.');
        }
        $this->assertOperation($landing, $operation);
        $result = TaskLandingData::object($operation->result);
        $lineage = TaskLandingData::object($result['lineage'] ?? null);
        if (($lineage['flow'] ?? null) !== 'proof' || ($lineage['candidate'] ?? null) !== $landing->candidate_sha
            || ($lineage['merge'] ?? null) !== ($merge['merge_sha'] ?? null)
            || $operation->operation !== 'verify:'.TaskLandingData::hash($result)) {
            throw new LogicException('Postmerge proof requires its exact native verification.');
        }

        return $result;
    }

    /** @return array<string,mixed>|null */
    private function inspectPrimary(TaskLanding $landing, string $main): ?array
    {
        $repository = $landing->workspace()->firstOrFail()->repository;
        if ($repository === '/' || realpath($repository) !== $repository) {
            throw new LogicException('The canonical primary checkout is unavailable.');
        }
        $read = function (array $arguments) use ($repository): string {
            $result = Process::path($repository)->env([...TaskProcessEnvironment::isolated(), 'GIT_OPTIONAL_LOCKS' => '0',
                'GIT_NO_LAZY_FETCH' => '1', 'GIT_TERMINAL_PROMPT' => '0'])->timeout(30)->run(['git', '--no-replace-objects', ...$arguments]);
            if ($result->failed()) {
                throw new LogicException('Cannot inspect the primary checkout without mutation.');
            }

            return trim($result->output());
        };
        if ($read(['status', '--porcelain']) !== '' || $read(['branch', '--show-current']) !== 'main') {
            throw new LogicException('The primary checkout must be clean main; preserve unrelated work.');
        }
        if ($read(['rev-parse', 'origin/main']) !== $main) {
            throw new LogicException('The verified remote main changed; verify before continuing.');
        }

        return $read(['rev-parse', 'HEAD']) === $main ? ['repository' => $repository, 'main_sha' => $main] : null;
    }

    private function assertOperation(TaskLanding $landing, TaskCloseoutOperation $operation): void
    {
        if ($operation->package_hash !== $landing->package_hash || ($operation->input['package_hash'] ?? null) !== $landing->package_hash
            || $operation->input_hash !== TaskLandingData::hash($operation->input)
            || ($operation->result !== null && $operation->state !== 'completed')) {
            throw new LogicException('The proof closeout operation no longer matches its immutable intent.');
        }
    }
}
