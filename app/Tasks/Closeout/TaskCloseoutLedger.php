<?php

declare(strict_types=1);

namespace App\Tasks\Closeout;

use App\Models\TaskCloseoutOperation;
use App\Models\TaskLanding;
use App\Tasks\Landing\TaskLandingData;
use App\Tasks\TaskPayload;
use Closure;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final readonly class TaskCloseoutLedger
{
    public function __construct(private TaskPayload $payloads) {}

    /** Caller holds the coordinator lock. External calls never run inside a DB transaction.
     * @param  array<string,mixed>  $input
     * @param  Closure():?array<string,mixed>  $inspect
     * @param  Closure():void  $execute
     * @param  Closure():array<string,mixed>|null  $beforeExecute
     * @return array<string,mixed>
     */
    public function step(TaskLanding $landing, string $name, array $input, Closure $inspect, Closure $execute, ?Closure $beforeExecute = null): array
    {
        $operation = TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $name)->first();
        $operation ??= TaskCloseoutOperation::query()->create(['task_landing_id' => $landing->id, 'operation' => $name,
            'assignment' => (string) Str::uuid(), 'package_hash' => $landing->package_hash,
            'input' => $input, 'input_hash' => TaskLandingData::hash($input)]);
        if ($operation->package_hash !== $landing->package_hash || ! $this->payloads->matches($operation->input, $input)
            || $operation->input_hash !== TaskLandingData::hash($operation->input)) {
            throw new LogicException('The closeout operation differs from its durable intent.');
        }
        if ($operation->result !== null) {
            return $operation->result;
        }
        try {
            $result = $inspect();
            if ($result === null && $operation->state === 'prepared') {
                $preflight = $beforeExecute === null ? [] : $beforeExecute();
                $operation->update(['state' => 'intended', 'preflight' => $preflight, 'error' => null]);
                try {
                    $execute();
                } catch (Throwable) {
                    // Exact read-back, not the write response, establishes success.
                }
                $result = $inspect();
            }
            if ($result === null) {
                throw new LogicException('The closeout operation remains uncertain; reconcile its exact intent without replaying the write.');
            }
            $operation->update(['result' => $result, 'state' => 'completed', 'error' => null]);

            return $result;
        } catch (Throwable $exception) {
            if ($operation->result === null) {
                $operation->update(['state' => $operation->state === 'prepared' ? 'prepared' : 'unknown',
                    'error' => $operation->state === 'prepared' ? 'Preflight failed before any write intent.' : 'Explicit closeout operation is unresolved; no automatic mutation retry.']);
            }
            throw $exception;
        }
    }

    /** @return array<string,mixed>|null */
    public function result(TaskLanding $landing, string $name): ?array
    {
        return TaskCloseoutOperation::query()->where('task_landing_id', $landing->id)->where('operation', $name)->first()?->result;
    }

    /** Explicit repeatable verification only; never use to perform a PR, merge or cleanup mutation.
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $result
     */
    public function record(TaskLanding $landing, string $name, array $input, array $result): void
    {
        $recorded = $this->step($landing, $name, $input, fn (): array => $result,
            fn (): never => throw new LogicException('A verification observation cannot execute a mutation.'));
        if (! $this->payloads->matches($recorded, $result)) {
            throw new LogicException('A retained verification observation cannot be changed.');
        }
    }
}
