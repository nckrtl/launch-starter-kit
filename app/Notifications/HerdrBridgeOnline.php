<?php

namespace App\Notifications;

use App\Contracts\SendsSlackNotifications;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;

final class HerdrBridgeOnline extends Notification implements SendsSlackNotifications
{
    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return [SlackChannel::class];
    }

    public function toSlack(object $notifiable): SlackMessage
    {
        return new SlackMessage('Commander: Herdr bridge online');
    }
}
