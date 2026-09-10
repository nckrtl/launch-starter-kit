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
        return new SlackMessage(self::text(
            $this->event->agent_name ?? $this->event->pane_id,
            $this->event->to_status,
        ));
    }

    public static function text(string $agent, string $status): string
    {
        return sprintf(
            'Herdr: %s just went %s',
            $agent,
            self::label($status),
        );
    }

    /**
     * The wording for a Herdr status from config('herdr.status_labels'); the status itself when it has no label.
     */
    private static function label(string $status): string
    {
        $labels = config('herdr.status_labels');
        $label = is_array($labels) ? ($labels[$status] ?? null) : null;

        return is_string($label) && $label !== '' ? $label : $status;
    }
}
