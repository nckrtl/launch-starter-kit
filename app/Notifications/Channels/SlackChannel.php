<?php

namespace App\Notifications\Channels;

use App\Contracts\SendsSlackNotifications;
use Illuminate\Http\Client\Factory;
use Illuminate\Notifications\Notification;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

final readonly class SlackChannel
{
    public function __construct(private Factory $http) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (! $notification instanceof SendsSlackNotifications) {
            throw new LogicException(sprintf(
                '%s must implement %s.',
                $notification::class,
                SendsSlackNotifications::class,
            ));
        }

        if (! method_exists($notifiable, 'routeNotificationFor')) {
            throw new InvalidArgumentException('The notifiable object cannot route notifications.');
        }

        $channel = $notifiable->routeNotificationFor('slack', $notification);

        if ($channel === null || $channel === '') {
            return;
        }

        if (! is_string($channel)) {
            throw new InvalidArgumentException('The Slack notification route must be a channel ID.');
        }

        $token = config('services.slack.notifications.bot_user_oauth_token');

        if (! is_string($token) || $token === '') {
            throw new LogicException('SLACK_BOT_USER_OAUTH_TOKEN is not configured.');
        }

        $response = $this->http
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout(3)
            ->timeout(10)
            ->retry(3, 250)
            ->post('https://slack.com/api/chat.postMessage', [
                'channel' => $channel,
                ...$notification->toSlack($notifiable)->toArray(),
            ]);

        $response->throw();

        if ($response->json('ok') !== true) {
            $error = $response->json('error');
            $error = is_string($error) ? $error : 'unknown_error';

            throw new RuntimeException("Slack API request failed: {$error}");
        }
    }
}
