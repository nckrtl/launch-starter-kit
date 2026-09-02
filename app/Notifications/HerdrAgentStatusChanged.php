<?php

namespace App\Notifications;

use App\Contracts\SendsSlackNotifications;
use App\Models\HerdrEvent;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

final class HerdrAgentStatusChanged extends Notification implements SendsSlackNotifications
{
    public function __construct(public readonly HerdrEvent $event) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SlackChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return new SlackMessage(sprintf(
            'Herdr: %s (%s, %s) is now %s',
            $this->event->agent_name ?? $this->event->pane_id,
            $this->event->workspace_label ?? $this->event->workspace_id,
            $this->event->pane_id,
            $this->event->to_status,
        ));
    }
}
