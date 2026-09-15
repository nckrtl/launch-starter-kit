<?php

declare(strict_types=1);

namespace App\Models;

use App\Tasks\Enums\TaskKind;
use App\Tasks\Enums\TaskStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use LogicException;

/**
 * @property int $id
 * @property string $project_id
 * @property int|null $parent_id
 * @property TaskKind $kind
 * @property string $title
 * @property string $description
 * @property string $acceptance_criteria
 * @property string|null $creation_key
 * @property TaskStatus $status
 * @property CarbonImmutable|null $completed_at
 * @property int|null $accepted_task_run_id
 * @property-read TaskRun|null $acceptedRun
 * @property-read Task|null $parent
 * @property-read Collection<int, Task> $children
 * @property-read Collection<int, Task> $dependencies
 * @property-read Collection<int, Task> $dependents
 * @property-read Collection<int, TaskRun> $runs
 */
final class Task extends Model
{
    protected $fillable = ['project_id', 'parent_id', 'kind', 'title', 'description', 'acceptance_criteria', 'creation_key', 'status', 'completed_at', 'accepted_task_run_id'];

    protected $attributes = ['kind' => 'executable', 'status' => 'pending', 'description' => '', 'acceptance_criteria' => ''];

    protected static function booted(): void
    {
        self::updating(function (self $task): void {
            if ($task->getRawOriginal('status') === TaskStatus::Completed->value
                && $task->isDirty(['status', 'completed_at', 'accepted_task_run_id'])) {
                throw new LogicException('Task acceptance is immutable.');
            }

            if ($task->isDirty(['project_id', 'parent_id', 'kind', 'creation_key'])) {
                throw new LogicException('Task project, parent, and kind are immutable.');
            }

            if ($task->isDirty(['title', 'description', 'acceptance_criteria']) && $task->runs()->exists()) {
                throw new LogicException('An attempted task description is immutable.');
            }
        });

        self::deleting(fn (): never => throw new LogicException('Tasks retain execution and dependency history.'));
    }

    protected function casts(): array
    {
        return ['kind' => TaskKind::class, 'status' => TaskStatus::class, 'completed_at' => 'immutable_datetime'];
    }

    public function contentVersion(): string
    {
        return hash('sha256', json_encode([$this->title, $this->description, $this->acceptance_criteria], JSON_THROW_ON_ERROR));
    }

    /** @param Builder<self> $query */
    public function scopeForProject(Builder $query, string $projectId): void
    {
        $query->where('project_id', $projectId);
    }

    /** @return BelongsTo<self, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<self, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return BelongsToMany<self, $this> */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'task_id', 'depends_on_task_id');
    }

    /** @return BelongsToMany<self, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'task_dependencies', 'depends_on_task_id', 'task_id');
    }

    /** @return HasMany<TaskRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(TaskRun::class);
    }

    /** @return BelongsTo<TaskRun, $this> */
    public function acceptedRun(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class, 'accepted_task_run_id');
    }
}
