<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

final class TaskRecovery extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'root_task_id', 'evidence_path', 'evidence_sha256', 'source_path', 'source_sha256', 'backup_path', 'backup_sha256', 'provenance', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Task recovery provenance is immutable.'));
        self::deleting(fn (): never => throw new LogicException('Task recovery provenance is immutable.'));
    }

    protected function casts(): array
    {
        return ['provenance' => 'array', 'created_at' => 'immutable_datetime'];
    }
}
