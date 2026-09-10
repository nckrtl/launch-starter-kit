<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\ReceiptValidationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * @property int $id
 * @property int $phase_run_id
 * @property string $kind
 * @property int $schema_version
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property string|null $candidate_sha
 * @property ReceiptValidationStatus $validation_status
 * @property list<string>|null $validation_errors
 * @property CarbonImmutable $captured_at
 * @property CarbonImmutable|null $validated_at
 * @property-read PhaseRun $phaseRun
 */
final class Receipt extends Model
{
    protected static function booted(): void
    {
        self::updating(function (self $receipt): void {
            if ($receipt->isDirty(['phase_run_id', 'kind', 'schema_version', 'payload', 'payload_hash', 'candidate_sha', 'captured_at'])) {
                throw new LogicException('Captured receipt data is immutable.');
            }
        });

        self::deleting(fn (): never => throw new LogicException('Receipts are immutable audit records.'));
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'validation_status' => ReceiptValidationStatus::class,
            'validation_errors' => 'array',
            'captured_at' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PhaseRun, $this> */
    public function phaseRun(): BelongsTo
    {
        return $this->belongsTo(PhaseRun::class);
    }
}
