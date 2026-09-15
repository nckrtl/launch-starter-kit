<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int $task_run_id
 * @property int $task_agent_dispatch_id
 * @property int $task_run_review_id
 * @property int $round
 * @property string $binding_hash
 * @property array<string, mixed> $binding
 */
final class TaskArtifactReviewBinding extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_workspace_id', 'task_run_id', 'task_agent_dispatch_id', 'task_run_review_id', 'round', 'binding_hash', 'binding', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Artifact review bindings are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Artifact review bindings retain history.'));
    }

    protected function casts(): array
    {
        return ['round' => 'integer', 'binding' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
