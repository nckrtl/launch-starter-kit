<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @phpstan-import-type ReattemptBinding from \App\Tasks\Runtime\TaskReattemptGuard
 *
 * @property int $id
 * @property int $task_workspace_id
 * @property int $task_agent_dispatch_id
 * @property string $request_hash
 * @property ReattemptBinding $binding
 * @property array<string, string> $observation
 * @property array<string, mixed> $request
 */
final class TaskReattemptCheckpoint extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_workspace_id', 'task_agent_dispatch_id', 'request_hash', 'binding', 'observation', 'request', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Reattempt checkpoints are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Reattempt checkpoints retain preserved work and history.'));
    }

    protected function casts(): array
    {
        return ['binding' => 'array', 'observation' => 'array', 'request' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
