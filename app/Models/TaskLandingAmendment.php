<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int $predecessor_id
 * @property int $successor_id
 * @property string $request_hash
 * @property array<string, mixed> $request
 * @property string $audit_hash
 * @property array<string, mixed> $audit
 */
final class TaskLandingAmendment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Landing amendment audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Retain landing amendment history.'));
    }

    protected function casts(): array
    {
        return ['request' => 'array', 'audit' => 'array'];
    }
}
