<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int $task_agent_dispatch_id
 * @property int|null $task_id
 * @property string $mode
 * @property string $request_hash
 * @property string $head
 * @property string $previous_manifest_hash
 * @property string $manifest_hash
 * @property int $final_round
 * @property string $previous_attention
 * @property array<string, mixed>|null $previous_result
 * @property array<string, mixed> $request
 * @property array<string, mixed>|null $previous_manifest
 * @property array<string, mixed>|null $manifest
 */
final class TaskFinalContinuation extends Model
{
    public $timestamps = false;

    protected $fillable = ['task_workspace_id', 'task_agent_dispatch_id', 'task_id', 'mode', 'request_hash', 'head',
        'previous_manifest_hash', 'manifest_hash', 'final_round', 'previous_attention', 'previous_result', 'request', 'previous_manifest', 'manifest', 'created_at'];

    protected static function booted(): void
    {
        self::updating(fn (): never => throw new LogicException('Final continuation audits are immutable.'));
        self::deleting(fn (): never => throw new LogicException('Final continuation audits retain history.'));
    }

    protected function casts(): array
    {
        return ['request' => 'array', 'previous_result' => 'array', 'previous_manifest' => 'array', 'manifest' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        foreach (['previous_manifest', 'manifest'] as $key) {
            if (($attributes[$key] ?? null) === null) {
                unset($attributes[$key]);
            }
        }

        return $attributes;
    }
}
