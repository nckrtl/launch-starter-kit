<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $task_workspace_id
 * @property int $final_dispatch_id
 * @property string $issue_id
 * @property string $candidate_sha
 * @property string $input_hash
 * @property array<string, mixed> $request
 * @property array<string, mixed> $inputs
 * @property string $artifact_ref
 * @property string|null $artifact_sha
 * @property array<string, mixed>|null $package
 * @property string|null $package_hash
 * @property string $state
 * @property string|null $review_assignment
 * @property array<string, mixed>|null $review_session
 * @property string|null $review_token_hash
 * @property string|null $review_token
 * @property string|null $review_prompt
 * @property array<string, mixed>|null $review_result
 * @property string|null $error
 */
final class TaskLanding extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['state' => 'prepared'];

    protected $hidden = ['review_token', 'review_token_hash', 'review_prompt'];

    protected static function booted(): void
    {
        self::updating(function (self $landing): void {
            if ($landing->isDirty(['task_workspace_id', 'final_dispatch_id', 'issue_id', 'candidate_sha', 'input_hash', 'request', 'inputs', 'artifact_ref'])
                || ($landing->getRawOriginal('package_hash') !== null && $landing->isDirty(['artifact_sha', 'package', 'package_hash']))
                || ($landing->getRawOriginal('review_assignment') !== null && $landing->isDirty(['review_assignment', 'review_session', 'review_token', 'review_token_hash', 'review_prompt']))
                || ($landing->getRawOriginal('review_result') !== null && $landing->isDirty())) {
                throw new LogicException('Landing inputs, frozen packages, review assignments and verdicts are immutable.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Task landings retain package and approval history.'));
    }

    protected function casts(): array
    {
        return ['request' => 'array', 'inputs' => 'array', 'package' => 'array', 'review_session' => 'array',
            'review_result' => 'array', 'review_token' => 'encrypted', 'review_prompt' => 'encrypted'];
    }

    /** @return BelongsTo<TaskWorkspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(TaskWorkspace::class, 'task_workspace_id');
    }
}
