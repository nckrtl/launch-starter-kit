<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $ingestion_id
 * @property string $provider
 * @property string|null $provider_event_id
 * @property string $event_kind
 * @property array<string, mixed> $payload
 * @property string $payload_hash
 * @property int|null $delivery_id
 * @property int|null $agent_dispatch_id
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property CarbonImmutable|null $failed_at
 * @property string|null $failure_message
 * @property-read Delivery|null $delivery
 * @property-read AgentDispatch|null $agentDispatch
 */
final class ExternalEvent extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }

    /** @param Builder<self> $query */
    public function scopeUnprocessed(Builder $query): void
    {
        $query->whereNull('processed_at')->whereNull('failed_at');
    }

    /** @return BelongsTo<Delivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(Delivery::class);
    }

    /** @return BelongsTo<AgentDispatch, $this> */
    public function agentDispatch(): BelongsTo
    {
        return $this->belongsTo(AgentDispatch::class);
    }
}
