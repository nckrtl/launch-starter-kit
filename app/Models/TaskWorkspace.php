<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @property int $id
 * @property int $root_task_id
 * @property string $project_id
 * @property string $source_key
 * @property string $repository
 * @property string $worktree
 * @property string $base_sha
 * @property string $manifest_hash
 * @property array<string, mixed> $configuration
 * @property int|null $orbit_node_id
 * @property int|null $orbit_herdr_session_id
 * @property string|null $orbit_herdr_session
 * @property string|null $orbit_herdr_observer_origin
 * @property array<string, mixed>|null $herdr_workspace
 * @property array<string, mixed>|null $reviewer_session
 * @property array<string, mixed>|null $final_check
 * @property array<string, mixed>|null $final_result
 * @property string|null $attention
 */
final class TaskWorkspace extends Model
{
    private bool $continuingFinal = false;

    protected $fillable = ['root_task_id', 'project_id', 'source_key', 'repository', 'worktree', 'base_sha', 'manifest_hash', 'configuration', 'orbit_node_id', 'orbit_herdr_session_id', 'orbit_herdr_session', 'orbit_herdr_observer_origin', 'herdr_workspace', 'reviewer_session', 'final_check', 'final_result', 'attention'];

    protected static function booted(): void
    {
        self::updating(function (self $workspace): void {
            if ($workspace->isDirty(['root_task_id', 'project_id', 'source_key', 'repository', 'worktree', 'base_sha', 'manifest_hash', 'configuration', 'orbit_node_id', 'orbit_herdr_session_id', 'orbit_herdr_session', 'orbit_herdr_observer_origin'])
                || (! $workspace->continuingFinal && $workspace->getRawOriginal('final_result') !== null && $workspace->isDirty(['final_check', 'final_result']))) {
                throw new LogicException('Task workspace assignment, configuration, and final results are immutable.');
            }
        });

        self::deleting(fn (): never => throw new LogicException('Task workspaces retain execution history.'));
    }

    public function continueFinal(TaskFinalContinuation $continuation): void
    {
        if (! $continuation->exists || $continuation->task_workspace_id !== $this->id
            || $continuation->isDirty() || $continuation->fresh()?->toArray() != $continuation->toArray()
            || $continuation->previous_attention !== $this->attention
            || $continuation->previous_result !== $this->final_result
            || ($this->final_result['verdict'] ?? null) === 'pass'
            || $this->dispatches()->orderByDesc('id')->first()?->id !== $continuation->task_agent_dispatch_id) {
            throw new LogicException('Only the exact audited final continuation can release its hold.');
        }
        $this->continuingFinal = true;
        try {
            $this->update(['attention' => null, 'final_check' => null, 'final_result' => null]);
        } finally {
            $this->continuingFinal = false;
        }
    }

    protected function casts(): array
    {
        return ['configuration' => 'array', 'orbit_node_id' => 'integer', 'orbit_herdr_session_id' => 'integer', 'herdr_workspace' => 'array', 'reviewer_session' => 'array', 'final_check' => 'array', 'final_result' => 'array'];
    }

    /** @return BelongsTo<Task, $this> */
    public function root(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'root_task_id');
    }

    /** @return HasMany<TaskAgentDispatch, $this> */
    public function dispatches(): HasMany
    {
        return $this->hasMany(TaskAgentDispatch::class);
    }
}
