<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int|null $task_run_id
 * @property string $step_key
 * @property string $kind
 * @property int $round
 * @property string $state
 * @property string|null $execution_key
 * @property string $token_hash
 * @property string $handoff_token
 * @property string $prompt
 * @property array<string, mixed>|null $session
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $final_check
 * @property int|null $final_check_version
 * @property int|null $final_preflight_version
 * @property array<string, mixed>|null $final_preflight
 * @property string|null $error
 */
final class TaskAgentDispatch extends Model
{
    protected $fillable = ['task_workspace_id', 'task_run_id', 'step_key', 'kind', 'round', 'state', 'execution_key', 'token_hash', 'handoff_token', 'prompt', 'session', 'result', 'error', 'final_check', 'final_check_version', 'final_preflight_version', 'final_preflight'];

    protected $attributes = ['state' => 'prepared', 'round' => 0];

    protected $hidden = ['prompt', 'token_hash', 'handoff_token'];

    protected static function booted(): void
    {
        self::updating(function (self $dispatch): void {
            if ($dispatch->isDirty(['task_workspace_id', 'task_run_id', 'step_key', 'kind', 'round', 'token_hash', 'handoff_token', 'final_check_version', 'final_preflight_version'])
                || ($dispatch->getRawOriginal('final_check') !== null && $dispatch->isDirty('final_check'))
                || ($dispatch->getRawOriginal('final_preflight') !== null && $dispatch->isDirty('final_preflight'))
                || (in_array($dispatch->getRawOriginal('state'), ['acknowledged', 'check_failed', 'integration_required'], true) && $dispatch->isDirty())) {
                throw new LogicException('Dispatch assignments and acknowledged handoffs are immutable.');
            }
        });

        self::deleting(fn (): never => throw new LogicException('Task dispatches retain handoff history.'));
    }

    protected function casts(): array
    {
        return ['prompt' => 'encrypted', 'handoff_token' => 'encrypted', 'session' => 'array', 'result' => 'array', 'final_check' => 'array', 'final_check_version' => 'integer', 'final_preflight_version' => 'integer', 'final_preflight' => 'array'];
    }

    public function attributesToArray(): array
    {
        $attributes = parent::attributesToArray();
        foreach (['final_preflight_version', 'final_preflight'] as $key) {
            if (($attributes[$key] ?? null) === null) {
                unset($attributes[$key]);
            }
        }

        return $attributes;
    }

    /** @return BelongsTo<TaskWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(TaskWorkspace::class, 'task_workspace_id');
    }

    /** @return BelongsTo<TaskRun, $this> */
    public function run(): BelongsTo
    {
        return $this->belongsTo(TaskRun::class, 'task_run_id');
    }
}
