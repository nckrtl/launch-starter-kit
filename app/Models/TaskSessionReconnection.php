<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int $task_agent_dispatch_id
 * @property string $request_hash
 * @property array<string, mixed> $request
 * @property array<string, mixed> $binding
 * @property array<string, mixed> $sessions
 */
final class TaskSessionReconnection extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_workspace_id', 'task_agent_dispatch_id', 'request_hash', 'request', 'binding', 'sessions', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Session reconnection audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Retain session reconnection audits.'));
    }

    protected function casts(): array
    {
        return ['request' => 'array', 'binding' => 'array', 'sessions' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
