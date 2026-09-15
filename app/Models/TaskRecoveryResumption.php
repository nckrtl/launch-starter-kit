<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property string $id
 * @property int $task_workspace_id
 * @property string $task_recovery_id
 * @property string $request_hash
 * @property array<string, mixed> $evidence
 */
final class TaskRecoveryResumption extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'task_workspace_id', 'task_recovery_id', 'request_hash', 'database_path', 'head',
        'manifest_hash', 'previous_attention', 'reviewer_session', 'herdr_workspace', 'evidence', 'advance_requested', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Task recovery resumption audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Task recovery resumption audits are immutable.'));
    }

    protected function casts(): array
    {
        return ['reviewer_session' => 'array', 'herdr_workspace' => 'array', 'evidence' => 'array',
            'advance_requested' => 'boolean', 'created_at' => 'immutable_datetime'];
    }
}
