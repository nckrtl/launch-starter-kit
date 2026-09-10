<?php

use App\Contracts\SendsSlackNotifications;
use App\Notifications\Channels\SlackChannel;
use App\Notifications\Messages\SlackMessage;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;

beforeEach(function () {
    config(['services.slack.notifications.bot_user_oauth_token' => 'xoxb-test-token']);
});

it('routes a notification to the requested Slack channel', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response(['ok' => true]),
    ]);

    $notification = new class extends Notification implements SendsSlackNotifications
    {
        public function via(object $notifiable): array
        {
            return [SlackChannel::class];
        }

        public function toSlack(object $notifiable): SlackMessage
        {
            return new SlackMessage('Deployment completed.');
        }
    };

    NotificationFacade::route('slack', 'C0123456789')->notify($notification);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://slack.com/api/chat.postMessage'
        && $request['channel'] === 'C0123456789'
        && $request['text'] === 'Deployment completed.'
        && $request->hasHeader('Authorization', 'Bearer xoxb-test-token')
    );
});

it('surfaces Slack API errors', function () {
    Http::fake([
        'slack.com/api/chat.postMessage' => Http::response([
            'ok' => false,
            'error' => 'channel_not_found',
        ]),
    ]);

    $notification = new class extends Notification implements SendsSlackNotifications
    {
        public function via(object $notifiable): array
        {
            return [SlackChannel::class];
        }

        public function toSlack(object $notifiable): SlackMessage
        {
            return new SlackMessage('Deployment failed.');
        }
    };

    expect(fn () => NotificationFacade::route('slack', 'C0000000000')->notify($notification))
        ->toThrow(RuntimeException::class, 'channel_not_found');
});

it('retries a transient Slack HTTP failure', function () {
    Http::fakeSequence()
        ->push(['ok' => false], 500)
        ->push(['ok' => true]);

    $notification = new class extends Notification implements SendsSlackNotifications
    {
        public function via(object $notifiable): array
        {
            return [SlackChannel::class];
        }

        public function toSlack(object $notifiable): SlackMessage
        {
            return new SlackMessage('Deployment recovered.');
        }
    };

    NotificationFacade::route('slack', 'C0123456789')->notify($notification);

    Http::assertSentCount(2);
});
