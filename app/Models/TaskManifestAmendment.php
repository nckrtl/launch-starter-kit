<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** @property int $id
 * @property int $task_workspace_id
 * @property int $active_task_run_id
 * @property int $task_agent_dispatch_id
 * @property int $target_task_id
 * @property int $sequence
 * @property string $amendment_key
 * @property string $proposal_hash
 * @property string $request_hash
 * @property string $head
 * @property string $previous_manifest_hash
 * @property string $manifest_hash
 * @property array<string, mixed> $previous_manifest
 * @property array<string, mixed> $manifest
 * @property array<string, mixed> $request
 * @property string $audit_hash
 */
final class TaskManifestAmendment extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_workspace_id', 'active_task_run_id', 'task_agent_dispatch_id', 'target_task_id',
        'sequence', 'amendment_key', 'proposal_hash', 'request_hash', 'head', 'previous_manifest_hash',
        'manifest_hash', 'previous_manifest', 'manifest', 'request', 'audit_hash', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Task manifest amendment audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Task manifest amendment audits retain history.'));
    }

    protected function casts(): array
    {
        return ['sequence' => 'integer', 'previous_manifest' => 'array', 'manifest' => 'array',
            'request' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
