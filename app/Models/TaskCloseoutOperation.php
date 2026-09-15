<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property int $task_landing_id
 * @property string $operation
 * @property string $assignment
 * @property string $package_hash
 * @property array<string,mixed> $input
 * @property string $input_hash
 * @property string $state
 * @property array<string,mixed>|null $preflight
 * @property array<string,mixed>|null $result
 * @property string|null $error
 */
final class TaskCloseoutOperation extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['state' => 'prepared'];

    protected static function booted(): void
    {
        self::updating(function (self $operation): void {
            if ($operation->isDirty(['task_landing_id', 'operation', 'assignment', 'package_hash', 'input', 'input_hash'])
                || ($operation->getRawOriginal('preflight') !== null && $operation->isDirty('preflight'))
                || ($operation->getRawOriginal('result') !== null && $operation->isDirty())) {
                throw new LogicException('Closeout intents and completed outcomes are immutable.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Retain closeout operation history.'));
    }

    protected function casts(): array
    {
        return ['input' => 'array', 'preflight' => 'array', 'result' => 'array'];
    }
}
