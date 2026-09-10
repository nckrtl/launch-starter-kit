<?php

namespace App\Herdr;

use App\Models\HerdrEvent;

/**
 * Stores a transition and sends a compact authenticated wake event to Tom.
 */
final readonly class TransitionRecorder
{
    public function __construct(private TomWebhookClient $webhook) {}

    public function record(Transition $transition, Roster $roster): HerdrEvent
    {
        $workspaceId = $roster->workspaceId($transition->paneId) ?? '';

        $event = HerdrEvent::create([
            'occurred_at' => now(),
            'workspace_id' => $workspaceId,
            'workspace_label' => $roster->workspaceLabel($workspaceId),
            'pane_id' => $transition->paneId,
            'agent' => $roster->agentKind($transition->paneId),
            'agent_name' => $roster->agentName($transition->paneId),
            'from_status' => $transition->from,
            'to_status' => $transition->to,
        ]);

        $this->webhook->send($event);

        $event->notified_at = now();
        $event->save();

        return $event;
    }
}
