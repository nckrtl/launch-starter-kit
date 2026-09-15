<?php

declare(strict_types=1);

namespace App\Tasks\Completion;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Closeout\TaskCloseoutLedger;
use App\Tasks\Closeout\TaskCloseoutLock;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\Landing\TaskLandingHistory;
use App\Tasks\TaskPayload;
use LogicException;

final readonly class CompleteTaskLanding
{
    public function __construct(private TaskCloseoutLock $lock, private TaskCloseoutLedger $ledger,
        private TaskCompletionEvidence $evidence, private TaskCompletionIssue $issues, private TaskPayload $payloads,
        private TaskLandingHistory $history) {}

    /** @return array<string,mixed> */
    public function handle(int $id, string $package, bool $exclusive, bool $apply = false): array
    {
        if (! $exclusive || ($apply && config('task-runtime.enabled') !== true)) {
            throw new LogicException('Use the exclusive Tasks coordinator and enable runtime before applying completion.');
        }
        $operation = function () use ($id, $package, $apply): array {
            $landing = TaskLanding::query()->findOrFail($id);
            $this->history->assert($landing);
            if ($landing->package_hash !== $package || preg_match('/\\A[a-f0-9]{64}\\z/', $package) !== 1) {
                throw new LogicException('Pin the exact independently reviewed completion package.');
            }
            $input = $this->evidence->input($landing);
            $admission = $this->evidence->admission($landing, $input);
            $current = $this->evidence->observe($landing);
            $linearInput = [...$input, 'done_state_id' => TaskLandingData::text($current, 'done_state_id'),
                'ownership' => $this->ownership($current)];
            $prior = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', 'delivery-complete')->first();
            if ($prior?->result !== null) {
                $delivered = $this->evidence->operation($landing, 'delivery-complete');
                $linear = $this->evidence->operation($landing, 'linear-completion');
                if (! $this->payloads->matches($delivered->input, $input)
                    || ! $this->payloads->matches($linear->input, $linearInput)) {
                    throw new LogicException('The completed delivery identity changed; retain history and reconcile drift.');
                }
                $this->same($linear->result, $current['completed_issue'] ?? null);
                $result = TaskLandingData::object($delivered->result);
                $observation = TaskLandingData::object($result['observation'] ?? null);
                if (($result['schema'] ?? null) !== 1 || ($result['state'] ?? null) !== 'delivered'
                    || ($result['package_hash'] ?? null) !== $package
                    || ($result['admission_hash'] ?? null) !== TaskLandingData::hash($admission)
                    || ($result['linear_operation_id'] ?? null) !== $linear->id
                    || ($result['linear_result_hash'] ?? null) !== TaskLandingData::hash($linear->result)
                    || ($result['observation_hash'] ?? null) !== TaskLandingData::hash($observation)) {
                    throw new LogicException('The retained delivered record differs from its admitted package and Linear completion.');
                }
                $this->same($observation['completed_issue'] ?? null, $linear->result);
                $this->same($observation['publication'] ?? null, $current['publication']);
                $this->same($observation['merge'] ?? null, $current['merge']);
                if (array_key_exists('native_proof_closeout', $observation) || array_key_exists('native_proof_closeout', $current)) {
                    $this->same($observation['native_proof_closeout'] ?? null, $current['native_proof_closeout'] ?? null);
                }

                return ['applied' => false, 'state' => 'delivered', 'delivery' => $result, 'current' => $current];
            }
            if (! $apply) {
                return ['applied' => false, 'state' => 'eligible', 'package_hash' => $package,
                    'action' => 'Complete the exact undelegated Linear issue and record delivered; no operational cleanup.', 'current' => $current];
            }
            $this->ledger->record($landing, 'completion-admission', $input, $admission);
            $pr = TaskLandingData::object($current['publication']);
            $completed = $this->ledger->step($landing, 'linear-completion', $linearInput,
                function () use ($landing, $pr, $linearInput): ?array {
                    $issue = $this->evidence->issue($landing, $pr);
                    $this->same($linearInput['ownership'], $this->ownership($issue));

                    return ($issue['completed_issue'] ?? null) === null ? null : TaskLandingData::object($issue['completed_issue']);
                },
                fn () => $this->issues->complete($landing, TaskLandingData::text($linearInput, 'done_state_id')),
                function () use ($landing, $input, $linearInput): array {
                    $this->evidence->admission($landing, $input);
                    $fresh = $this->evidence->observe($landing);
                    $this->same($linearInput['ownership'], $this->ownership($fresh));
                    if (($fresh['done_state_id'] ?? null) !== $linearInput['done_state_id'] || ($fresh['completed_issue'] ?? null) !== null) {
                        throw new LogicException('The issue completion preflight changed; observe it again without a mutation.');
                    }

                    return $fresh;
                });
            $current = $this->evidence->observe($landing);
            $this->same($completed, $current['completed_issue'] ?? null);
            $this->evidence->admission($landing, $input);
            $linear = $this->evidence->operation($landing, 'linear-completion');
            $result = ['schema' => 1, 'state' => 'delivered', 'package_hash' => $package,
                'admission_hash' => TaskLandingData::hash($admission), 'linear_operation_id' => $linear->id,
                'linear_result_hash' => TaskLandingData::hash($completed), 'observation' => $current,
                'observation_hash' => TaskLandingData::hash($current)];
            $this->ledger->record($landing, 'delivery-complete', $input, $result);

            return ['applied' => true, 'state' => 'delivered', 'delivery' => $result, 'current' => $current];
        };

        return $apply ? $this->lock->handle($operation) : $operation();
    }

    private function same(mixed $expected, mixed $actual): void
    {
        if (! is_array($expected) || ! is_array($actual)
            || ! $this->payloads->matches(TaskLandingData::object($expected), TaskLandingData::object($actual))) {
            throw new LogicException('Exact Linear Done, unchanged permitted ownership and contract are not confirmed; do not replay the write.');
        }
    }

    /** @param array<string,mixed> $observation
     * @return array<string,mixed>
     */
    private function ownership(array $observation): array
    {
        $issue = TaskLandingData::object($observation['issue_observation'] ?? null);
        if (! array_key_exists('assignee', $issue) || ! array_key_exists('delegate', $issue)) {
            throw new LogicException('Retain the exact observed completion ownership.');
        }

        return ['assignee' => $issue['assignee'], 'delegate' => $issue['delegate']];
    }
}
