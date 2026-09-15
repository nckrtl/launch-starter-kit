<?php

declare(strict_types=1);

namespace App\Models;

use App\Tasks\Enums\TaskRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @property int $id
 * @property int $task_id
 * @property int|null $active_task_id
 * @property int $root_task_id
 * @property int|null $active_root_task_id
 * @property int $attempt
 * @property string $idempotency_key
 * @property TaskRunStatus $status
 * @property array<string, mixed> $input
 * @property array<string, mixed>|null $output
 * @property string|null $failure_message
 * @property string $worker_ref
 * @property string $reviewer_ref
 * @property string|null $base_sha
 * @property string|null $commit_sha
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read Task $task
 * @property-read Collection<int, TaskRunReview> $reviews
 */
final class TaskRun extends Model
{
    protected $fillable = ['task_id', 'root_task_id', 'attempt', 'idempotency_key', 'worker_ref', 'reviewer_ref', 'base_sha', 'status', 'input', 'output', 'failure_message', 'commit_sha', 'started_at', 'finished_at'];

    protected $attributes = ['status' => 'running', 'input' => '[]'];

    protected static function booted(): void
    {
        self::saving(function (self $run): void {
            if ($run->exists && ($run->isDirty(['task_id', 'root_task_id', 'attempt', 'idempotency_key', 'worker_ref', 'reviewer_ref', 'base_sha', 'input', 'started_at'])
                || ($run->getRawOriginal('status') !== TaskRunStatus::Running->value && $run->isDirty()))) {
                throw new LogicException('Task run inputs and finished attempts are immutable.');
            }

            $run->active_task_id = $run->status === TaskRunStatus::Running ? $run->task_id : null;
            $run->active_root_task_id = $run->status === TaskRunStatus::Running ? $run->root_task_id : null;
        });

        self::deleting(fn (): never => throw new LogicException('Task runs are immutable audit records.'));
    }

    protected function casts(): array
    {
        return [
            'status' => TaskRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Task, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class);
    }

    /** @return HasMany<TaskRunReview, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(TaskRunReview::class);
    }
}
