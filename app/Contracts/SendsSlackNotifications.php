<?php

namespace App\Contracts;

use App\Notifications\Messages\SlackMessage;

interface SendsSlackNotifications
{
    public function toSlack(object $notifiable): SlackMessage;
}
