<?php

namespace App\Herdr;

use App\Models\HerdrEvent;
use App\Notifications\HerdrAgentStatusChanged;
use Illuminate\Support\Facades\Notification;
use LogicException;

/**
 * Stores a transition as a HerdrEvent and posts it to Slack.
 */
final readonly class TransitionRecorder
{
    public function record(Transition $transition, Roster $roster): HerdrEvent
    {
        $channel = config('herdr.slack_channel');

        if (! is_string($channel) || $channel === '') {
            throw new LogicException('HERDR_SLACK_CHANNEL is not configured.');
        }

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

        Notification::route('slack', $channel)->notify(new HerdrAgentStatusChanged($event));

        $event->notified_at = now();
        $event->save();

        return $event;
    }
}
