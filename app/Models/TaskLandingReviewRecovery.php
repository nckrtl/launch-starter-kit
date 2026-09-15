<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_landing_id
 * @property string $review_assignment
 * @property string $request_hash
 * @property array<string, mixed> $inputs
 * @property array<string, mixed> $evidence
 * @property array<string, mixed> $review_session
 * @property string $reference_prompt
 * @property string $prompt_sha256
 * @property int $prompt_bytes
 * @property string $wire_sha256
 * @property int $wire_bytes
 * @property string $state
 */
final class TaskLandingReviewRecovery extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['state' => 'intended'];

    protected $hidden = ['reference_prompt'];

    protected static function booted(): void
    {
        self::updating(function (self $recovery): void {
            if (array_diff(array_keys($recovery->getDirty()), ['state', 'error', 'updated_at']) !== []
                || $recovery->getRawOriginal('state') !== 'intended'
                || ! in_array($recovery->state, ['sent', 'unknown'], true)) {
                throw new LogicException('Recovery evidence and its single transport attempt are immutable.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Retain supplemental review recovery audit history.'));
    }

    protected function casts(): array
    {
        return ['inputs' => 'array', 'evidence' => 'array', 'review_session' => 'array', 'reference_prompt' => 'encrypted',
            'prompt_bytes' => 'integer', 'wire_bytes' => 'integer'];
    }
}
