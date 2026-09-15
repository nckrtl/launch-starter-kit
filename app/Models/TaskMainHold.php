<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * @property int $id
 * @property string $project_id
 * @property string $incident
 * @property array<string,mixed> $evidence
 * @property string $evidence_hash
 * @property array<array-key,array<string,mixed>> $repairs
 * @property array<string,mixed>|null $clearance
 */
final class TaskMainHold extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['repairs' => '[]'];

    protected static function booted(): void
    {
        self::updating(function (self $hold): void {
            if ($hold->isDirty(['project_id', 'incident', 'evidence', 'evidence_hash'])
                || ($hold->getRawOriginal('clearance') !== null && $hold->isDirty())) {
                throw new LogicException('Main hold evidence, repair exceptions and clearances are immutable.');
            }
            $original = $hold->getRawOriginal('repairs');
            if (! is_string($original)) {
                throw new LogicException('The retained repair authorization list is invalid.');
            }
            $old = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
            if ($hold->isDirty('repairs') && (! is_array($old) || ! array_is_list($hold->repairs)
                || count($hold->repairs) !== count($old) + 1 || array_slice($hold->repairs, 0, count($old)) !== $old)) {
                throw new LogicException('Main repair authorizations are append-only.');
            }
        });
        self::deleting(fn (): never => throw new LogicException('Retain main correctness incident history.'));
    }

    protected function casts(): array
    {
        return ['evidence' => 'array', 'repairs' => 'array', 'clearance' => 'array'];
    }
}
