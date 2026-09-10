<?php

declare(strict_types=1);

namespace App\Models;

use App\Delivery\Enums\ProjectOrchestrationState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * @property int $id
 * @property string $manifest_project_id
 * @property array<string, mixed> $config
 * @property ProjectOrchestrationState $state
 * @property-read Collection<int, Delivery> $deliveries
 * @property-read Collection<int, MaintenanceRun> $maintenanceRuns
 */
final class ProjectOrchestration extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = ['state' => 'enabled'];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'state' => ProjectOrchestrationState::class,
        ];
    }

    /** @return HasMany<Delivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(Delivery::class);
    }

    /** @return HasMany<MaintenanceRun, $this> */
    public function maintenanceRuns(): HasMany
    {
        return $this->hasMany(MaintenanceRun::class);
    }
}
