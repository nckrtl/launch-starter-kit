<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property CarbonInterface $occurred_at
 * @property string $workspace_id
 * @property string|null $workspace_label
 * @property string $pane_id
 * @property string|null $agent
 * @property string|null $agent_name
 * @property string|null $from_status
 * @property string $to_status
 * @property CarbonInterface|null $notified_at
 */
class HerdrEvent extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'notified_at' => 'datetime',
        ];
    }
}
