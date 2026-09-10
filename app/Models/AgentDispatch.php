<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\AgentDispatchStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property int $phase_run_id
 * @property string $agent_role
 * @property string $idempotency_key
 * @property string|null $herdr_session
 * @property string|null $herdr_workspace_id
 * @property string|null $herdr_tab_id
 * @property string|null $herdr_pane_id
 * @property string|null $herdr_terminal_id
 * @property string|null $herdr_agent_id
 * @property string|null $herdr_agent_name
 * @property string $prompt_name
 * @property int $prompt_version
 * @property string $prompt_hash
 * @property AgentDispatchStatus $status
 * @property int|null $state_change_seq
 * @property string|null $error_code
 * @property string|null $error_message
 * @property CarbonImmutable|null $dispatched_at
 * @property CarbonImmutable|null $settled_at
 * @property-read PhaseRun $phaseRun
 * @property-read Collection<int, ExternalEvent> $externalEvents
 */
final class AgentDispatch extends Model
{
    protected function casts(): array
    {
        return [
            'status' => AgentDispatchStatus::class,
            'dispatched_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<PhaseRun, $this> */
    public function phaseRun(): BelongsTo
    {
        return $this->belongsTo(PhaseRun::class);
    }

    /** @return HasMany<ExternalEvent, $this> */
    public function externalEvents(): HasMany
    {
        return $this->hasMany(ExternalEvent::class);
    }
}
