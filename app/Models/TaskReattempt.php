<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_reattempt_checkpoint_id
 * @property int $task_workspace_id
 * @property int $task_run_id
 * @property int $task_agent_dispatch_id
 * @property string $request_hash
 * @property array<string, string> $observation
 * @property array<string, mixed> $request
 */
final class TaskReattempt extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_reattempt_checkpoint_id', 'task_workspace_id', 'task_run_id', 'task_agent_dispatch_id', 'request_hash', 'observation', 'request', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Reattempt audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Reattempt audits retain execution history.'));
    }

    protected function casts(): array
    {
        return ['observation' => 'array', 'request' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
