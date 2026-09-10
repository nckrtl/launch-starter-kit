<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\PhaseRunStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $delivery_id
 * @property string $phase_name
 * @property int $attempt
 * @property PhaseRunStatus $status
 * @property string|null $current_block
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $output
 * @property string|null $failure_code
 * @property string|null $failure_message
 * @property array<string, mixed>|null $failure_details
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property-read Delivery $delivery
 * @property-read Collection<int, AgentDispatch> $agentDispatches
 * @property-read Collection<int, Receipt> $receipts
 */
final class PhaseRun extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PhaseRunStatus::class,
            'input' => 'array',
            'output' => 'array',
            'failure_details' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return HasMany<AgentDispatch, $this> */
    public function agentDispatches(): HasMany
    {
        return $this->hasMany(AgentDispatch::class);
    }

    /** @return HasMany<Receipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
